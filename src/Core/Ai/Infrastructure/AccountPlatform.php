<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldownNotSaved;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

// Excluded from the container because AccountFailoverPlatform builds one per call around an account's own platform.
#[Exclude]
final readonly class AccountPlatform implements PlatformInterface
{
    public function __construct(
        private Account $account,
        private PlatformInterface $platform,
        private AccountCooldowns $cooldowns,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SendFailures $failures,
        private PinnedProviderCooldowns $pinnedCooldowns,
        private ?PinnedProvider $pinned,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $sentAt = $this->clock->now();
        $this->failures->tried($this->account);

        try {
            $result = $this->platform->invoke($model, $input, $options);
            // Boundary: records every failure of the account and rethrows it, because a cooldown or a log must follow whatever the platform threw.
        } catch (Throwable $failure) {
            $this->failed($failure);

            // A guard for a platform that fails while sending only, because Symfony's HTTP client reports a failed name lookup when the answer is read, which the vendor retries instead.
            if ($failure instanceof TransportExceptionInterface) {
                $this->failures->unreachable($this->account);
            }

            if (!$this->coolDownAfter($failure) instanceof Closure) {
                // The failover gets no logger and chains no cause, so this is the only record of why the call was not sent.
                $this->logger->warning('Account {account} could not send the call.', ['account' => $this->account->key(), 'exception' => $failure]);
            }

            throw $failure;
        }

        $result->getMetadata()->add(Account::METADATA, $this->account->key());
        // A provider's limit arrives when the answer is read, not when the call is sent, so the cooldown hangs on the result.
        $failedWhenRead = fn (Throwable $failure) => $this->failed($failure, $result->getRawResult());
        $result->onError($failedWhenRead);
        // A limit that arrives while the stream is read never reaches onError, so the stream reports it too.
        $result->onConvert(fn (ResultInterface $answer): ResultInterface => $this->followAnswer($answer, $sentAt, $failedWhenRead));

        return $result;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }

    // A streamed answer clears the account's reported error only once it was read whole, because a stream can still fail after it converted.
    /**
     * @param Closure(Throwable): void $failed
     */
    private function followAnswer(ResultInterface $result, DateTimeImmutable $sentAt, Closure $failed): ResultInterface
    {
        if (!$result instanceof StreamResult) {
            $this->cooldowns->answered($this->account, $sentAt);

            return $result;
        }

        $result->addListener(new AccountStreamFailures($failed));
        $result->addListener(new AccountStreamAnswered(fn () => $this->cooldowns->answered($this->account, $sentAt)));

        return $result;
    }

    // The raw answer is known only once the call was sent, so a failure while sending has none.
    private function failed(Throwable $failure, ?RawResultInterface $answer = null): void
    {
        // Once the answer has started, StreamedAnswer wraps the provider's exception, so the cooldown looks through to it.
        $cause = $failure instanceof IncompleteStreamException ? $failure->getPrevious() ?? $failure : $failure;
        $coolDown = $this->coolDownAfter($cause, $answer);

        if ($coolDown instanceof Closure && $this->failures->record($this->account, $cause)) {
            // Logged whole, because the log redacts a key the provider's text echoes.
            $this->logger->warning('Account {account} failed.', ['account' => $this->account->key(), 'exception' => $cause]);
            $coolDown();
        }
    }

    // Matched by the exception type only, because the provider's text can change (plan T5); null for anything else, such as an answer Intakt could not read, because the account answered and stays usable (issue #1831).
    /**
     * @return (Closure(): void)|null
     */
    private function coolDownAfter(Throwable $failure, ?RawResultInterface $answer = null): ?Closure
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => fn () => $this->coolDownLimited($failure->getRetryAfter() ?? $this->retryAfterHeaderOf($answer)),
            // A revoked or wrong key will not work on the next call either, so the account is out like a limited one and the call goes elsewhere; remembered as a rejection so no call waits for it.
            $failure instanceof AuthenticationException => fn () => $this->cooldowns->coolDownRejected($this->account),
            $failure instanceof RetryableExceptionInterface => fn () => $this->cooldowns->coolDown($this->account, null),
            default => null,
        };
    }

    // A spent plan's limit comes without a reset (CodexAccountFailure), so only it waits for the plan's measured reset. The account cools before the pin, and a failing write of either is logged and costs neither the other its window nor the caller the provider's 429.
    private function coolDownLimited(?int $retryAfter): void
    {
        try {
            if (null === $retryAfter && $this->account->provider->spendsAPlan()) {
                $this->cooldowns->coolDownUntilPlanResets($this->account);
            } else {
                $this->cooldowns->coolDown($this->account, $retryAfter);
            }
            // Boundary: any failure of the account store is logged, because the provider's 429 must still reach the caller and the pin must still cool.
        } catch (Throwable $notSaved) {
            $this->logger->warning('The cooldown of account {account} could not be saved.', ['account' => $this->account->key(), 'exception' => $notSaved]);
        }

        $this->coolDownPinned($retryAfter);
    }

    // Symfony AI's generic bridge throws its 429 without the response's Retry-After (symfony/ai#2913, reproduced in upstream/ai-failover-platform-deferred-rate-limit), so the header is read from the raw answer as RFC 9110 defines it: delta-seconds of any length, or an HTTP date judged against the clock, capped at a day and at least a second; null when there is none or it does not parse, so the default minute applies.
    private function retryAfterHeaderOf(?RawResultInterface $answer): ?int
    {
        if (!$answer instanceof RawHttpResult) {
            return null;
        }

        try {
            $value = trim($answer->getObject()->getHeaders(false)['retry-after'][0] ?? '');
        } catch (ExceptionInterface) {
            return null;
        }

        $seconds = 1 === preg_match('~^\d+\z~', $value) ? $this->delaySecondsOf($value) : $this->secondsUntilDate($value);

        return null === $seconds ? null : max(1, min(AccountCooldowns::LONGEST_SECONDS, $seconds));
    }

    // Judged by the digits that matter, because a delay too long for an integer is still a valid delay of at least a day.
    private function delaySecondsOf(string $digits): int
    {
        $significant = ltrim($digits, '0');

        return strlen($significant) > strlen((string) AccountCooldowns::LONGEST_SECONDS) ? AccountCooldowns::LONGEST_SECONDS : (int) $significant;
    }

    // The three HTTP-date forms of RFC 9110 (section 5.6.7), matched whole and case-sensitive on ASCII: IMF-fixdate, the obsolete RFC 850 form and asctime, whose one-digit day is padded by a space (`Nov  9`). Read by hand instead of by PHP's date parser, which throws on a NUL, fixes a two-digit year to 1970 to 2069 and turns second 60 or minute 99 into another time.
    private function secondsUntilDate(string $value): ?int
    {
        $month = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
        $time = '(\d{2}):(\d{2}):(\d{2})';

        if (1 === preg_match('~^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), (\d{2}) '.$month.' (\d{4}) '.$time.' GMT\z~', $value, $date)) {
            return $this->secondsUntil($date[1], [(int) $date[4], $date[3], (int) $date[2]], [(int) $date[5], (int) $date[6], (int) $date[7]]);
        }

        if (1 === preg_match('~^(Mon|Tues|Wednes|Thurs|Fri|Satur|Sun)day, (\d{2})-'.$month.'-(\d{2}) '.$time.' GMT\z~', $value, $date)) {
            return $this->secondsUntil(substr($date[1], 0, 3), [$this->fullYearOf((int) $date[4]), $date[3], (int) $date[2]], [(int) $date[5], (int) $date[6], (int) $date[7]]);
        }

        if (1 === preg_match('~^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) '.$month.' (\d{2}| \d) '.$time.' (\d{4})\z~', $value, $date)) {
            return $this->secondsUntil($date[1], [(int) $date[7], $date[2], (int) trim($date[3])], [(int) $date[4], (int) $date[5], (int) $date[6]]);
        }

        return null;
    }

    // A date that does not exist (31 February, hour 24) or names the wrong weekday is no date, so the default minute applies instead of a wait it never named. Second 60 is a leap second, which RFC 9110 allows: it is the first second of the next minute.
    /**
     * @param array{int, string, int} $day  year, month name, day of the month
     * @param array{int, int, int}    $time hour, minute, second
     */
    private function secondsUntil(string $weekday, array $day, array $time): ?int
    {
        [$year, $monthName, $dayOfMonth] = $day;
        [$hour, $minute, $second] = $time;
        $month = 1 + (int) array_search($monthName, ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], true);
        if (!checkdate($month, $dayOfMonth, $year) || 23 < $hour || 59 < $minute || 60 < $second) {
            return null;
        }

        $midnight = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%04d-%d-%d', $year, $month, $dayOfMonth), new DateTimeZone('UTC'));
        if (false === $midnight || $weekday !== $midnight->format('D')) {
            return null;
        }

        return $midnight->getTimestamp() + 3600 * $hour + 60 * $minute + $second - $this->clock->now()->getTimestamp();
    }

    // RFC 9110: a two-digit year that would lie more than 50 years ahead is the latest past year with those digits; otherwise the year of this century, or of the next one when that is nearer.
    private function fullYearOf(int $twoDigits): int
    {
        $now = (int) $this->clock->now()->format('Y');
        $year = intdiv($now, 100) * 100 + $twoDigits;

        return match (true) {
            $year > $now + 50 => $year - 100,
            $year < $now - 50 => $year + 100,
            default => $year,
        };
    }

    // The pinned provider keeps its own window, which alone says how long the call waits for it (AccountFailoverPlatform); logged instead of thrown, because the provider's 429 must still reach the caller.
    private function coolDownPinned(?int $retryAfter): void
    {
        if (!$this->pinned instanceof PinnedProvider) {
            return;
        }

        try {
            $this->pinnedCooldowns->coolDown($this->pinned, $retryAfter ?? AccountCooldowns::SECONDS_WITHOUT_RESET);
        } catch (PinnedProviderCooldownNotSaved $notSaved) {
            $this->logger->warning('The cooldown of pinned provider {pinned} could not be saved.', ['pinned' => $this->pinned->key(), 'exception' => $notSaved]);
        }
    }
}
