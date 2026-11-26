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
        // Every account is read again right before it sends, the first one too, because the scan that found it free went on to read the accounts after it, and another worker can cool an account or its pin meanwhile; null only where no wait is read again.
        private ?ModelWaits $recheck = null,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        // Failed like a limited account, but before anything is recorded or sent, so the failover moves on and the account's own window stays as it is (issue #2860).
        $wait = $this->recheck?->ofAccount($this->account, $this->pinned?->routerProvider) ?? 0;
        if (0 < $wait) {
            throw new RateLimitExceededException($wait);
        }

        $sentAt = $this->clock->now();
        $this->failures->tried($this->account);

        try {
            $result = $this->platform->invoke($model, $input, $options);
            // Boundary: records every failure of the account and rethrows it, because a cooldown or a log must follow whatever the platform threw.
        } catch (Throwable $failure) {
            $accountError = $this->recordFailure($failure);

            // A guard for a platform that fails while sending only, because Symfony's HTTP client reports a failed name lookup when the answer is read, which the vendor retries instead.
            if ($failure instanceof TransportExceptionInterface) {
                $this->failures->unreachable($this->account);
            }

            if (!$accountError) {
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

    // True when the failure itself is an account error, also one already recorded, because the send path logs every other failure itself; the raw answer is known only once the call was sent, so a failure while sending has none.
    private function recordFailure(Throwable $failure, ?RawResultInterface $answer = null): bool
    {
        // Once the answer has started, StreamedAnswer wraps the provider's exception, so the cooldown looks through to it.
        $cause = $failure instanceof IncompleteStreamException ? $failure->getPrevious() ?? $failure : $failure;
        $coolDown = $this->coolDownAfter($cause, $answer);

        // A retryable wrapper counts as an account error, because the send path classified the thrown exception itself before it looked through to the cause.
        $thrownIsAccountError = $cause === $failure || $failure instanceof RetryableExceptionInterface;

        if (!$coolDown instanceof Closure) {
            return $failure instanceof RetryableExceptionInterface;
        }

        if ($this->failures->record($this->account, $cause)) {
            // Logged whole, because the log redacts a key the provider's text echoes.
            $this->logger->warning('Account {account} failed.', ['account' => $this->account->key(), 'exception' => $cause]);
            $coolDown();
        }

        // A wrapped limit still gets the send warning, because the wrapper is what was thrown.
        return $thrownIsAccountError;
    }

    // Void, because Symfony AI's onError and the stream listener take a callback that returns nothing.
    private function failed(Throwable $failure, ?RawResultInterface $answer = null): void
    {
        $this->recordFailure($failure, $answer);
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

    // Symfony AI's generic bridge throws its 429 without the response's Retry-After (symfony/ai#2913, reproduced in upstream/ai-failover-platform-deferred-rate-limit), so the header is read from the raw answer as RFC 9110 defines it: delta-seconds of any length, or an HTTP date judged against the clock, from visible ASCII and spaces only, capped at a day and at least a second; null when there is none or it does not parse, so the default minute applies.
    private function retryAfterHeaderOf(?RawResultInterface $answer): ?int
    {
        if (!$answer instanceof RawHttpResult) {
            return null;
        }

        try {
            // Only RFC 9110 optional whitespace is trimmed, because PHP's default set also strips NUL and other control bytes the check below must see.
            $value = trim($answer->getObject()->getHeaders(false)['retry-after'][0] ?? '', " \t");
        } catch (ExceptionInterface) {
            return null;
        }

        // Visible ASCII and spaces only, checked before any date is parsed, because PHP's date parser throws on a NUL byte and the provider's 429 must reach the caller unchanged.
        if (1 !== preg_match('~^[\x20-\x7E]*\z~', $value)) {
            return null;
        }

        $seconds = 1 === preg_match('~^\d+\z~', $value) ? $this->delaySecondsOf($value) : $this->secondsUntilDate($value);

        return null === $seconds ? null : max(1, min(AccountCooldowns::LONGEST_SECONDS, $seconds));
    }

    // A spent plan's limit comes without a reset (CodexAccountFailure), so only it waits for the plan's measured reset. A reset of zero or less waits the same second as a header of zero, because both stores save no window for it and the call could then report no wait at all. The account cools before the pin, and a failing write of either is logged and costs neither the other its window nor the caller the provider's 429.
    private function coolDownLimited(?int $reset): void
    {
        $retryAfter = null === $reset ? null : max(1, $reset);
        // Noted before either store is written, because a pinned call whose windows were not saved still has to wait what the limit named (AccountFailoverPlatform::typed()).
        $this->failures->limitedUntil($this->account, $this->clock->now()->getTimestamp() + min(AccountCooldowns::LONGEST_SECONDS, $retryAfter ?? AccountCooldowns::SECONDS_WITHOUT_RESET));

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

    // Judged by the digits that matter, because a delay too long for an integer is still a valid delay of at least a day.
    private function delaySecondsOf(string $digits): int
    {
        $significant = ltrim($digits, '0');

        return strlen($significant) > strlen((string) AccountCooldowns::LONGEST_SECONDS) ? AccountCooldowns::LONGEST_SECONDS : (int) $significant;
    }

    // The three HTTP-date forms of RFC 9110: IMF-fixdate, the obsolete RFC 850 form and asctime. Runs of spaces are read as one, because asctime pads a one-digit day with a space (`Nov  9`), which no PHP format writes. A date counts only when it formats back to that value, so one PHP reads by normalizing it (minute 99, a wrong weekday) falls back to the default minute instead of a wait it never named.
    private function secondsUntilDate(string $value): ?int
    {
        $utc = new DateTimeZone('UTC');
        $collapsed = (string) preg_replace('~ {2,}~', ' ', $value);

        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $at = DateTimeImmutable::createFromFormat('!'.$format, $collapsed, $utc);
            if (false !== $at && $at->format($format) === $collapsed) {
                return $at->getTimestamp() - $this->clock->now()->getTimestamp();
            }
        }

        return null;
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
