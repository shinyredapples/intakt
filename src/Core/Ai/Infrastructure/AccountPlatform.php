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
        // Null for the account the failover tries first, because the read that found it free was just made; a fallback account is read again, since another worker can cool it or its pin while an earlier account is tried.
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

    // A pinned router provider's 429 cools only the pin, so the account's other router providers and models, a pinned fallback among them, stay free (issue #2861); the account cools instead only when the pin could not be saved, so a 429 is never followed by a call at once. A failing write is logged and never costs the caller the provider's 429.
    private function coolDownLimited(?int $retryAfter): void
    {
        if ($this->coolDownPinned($retryAfter)) {
            return;
        }

        $this->coolDownAccount($retryAfter);
    }

    // A spent plan's limit comes without a reset (CodexAccountFailure), so only it waits for the plan's measured reset.
    private function coolDownAccount(?int $retryAfter): void
    {
        try {
            if (null === $retryAfter && $this->account->provider->spendsAPlan()) {
                $this->cooldowns->coolDownUntilPlanResets($this->account);
            } else {
                $this->cooldowns->coolDown($this->account, $retryAfter);
            }
            // Boundary: any failure of the account store is logged, because the provider's 429 must still reach the caller.
        } catch (Throwable $notSaved) {
            $this->logger->warning('The cooldown of account {account} could not be saved.', ['account' => $this->account->key(), 'exception' => $notSaved]);
        }
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

    // Judged by the digits that matter, because a delay too long for an integer is still a valid delay of at least a day.
    private function delaySecondsOf(string $digits): int
    {
        $significant = ltrim($digits, '0');

        return strlen($significant) > strlen((string) AccountCooldowns::LONGEST_SECONDS) ? AccountCooldowns::LONGEST_SECONDS : (int) $significant;
    }

    // The three HTTP-date forms of RFC 9110: IMF-fixdate, the obsolete RFC 850 form and asctime. A date counts only when it formats back to the header, so one PHP reads by normalizing it (minute 99, a wrong weekday) falls back to the default minute instead of a wait it never named.
    private function secondsUntilDate(string $value): ?int
    {
        $utc = new DateTimeZone('UTC');

        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $at = DateTimeImmutable::createFromFormat('!'.$format, $value, $utc);
            if (false !== $at && $this->formatsBack($at, $format, $value)) {
                return $at->getTimestamp() - $this->clock->now()->getTimestamp();
            }
        }

        return null;
    }

    // asctime pads a one-digit day with a space (`Nov  9`), which no PHP format writes, so runs of spaces are compared as one.
    private function formatsBack(DateTimeImmutable $at, string $format, string $value): bool
    {
        return $at->format($format) === preg_replace('~ {2,}~', ' ', $value);
    }

    // The pinned provider keeps its own window, which alone says how long the call waits for it (AccountFailoverPlatform); false when the model pins nothing or the window could not be saved, logged instead of thrown, because the provider's 429 must still reach the caller.
    private function coolDownPinned(?int $retryAfter): bool
    {
        if (!$this->pinned instanceof PinnedProvider) {
            return false;
        }

        try {
            $this->pinnedCooldowns->coolDown($this->pinned, $retryAfter ?? AccountCooldowns::SECONDS_WITHOUT_RESET);
        } catch (PinnedProviderCooldownNotSaved $notSaved) {
            $this->logger->warning('The cooldown of pinned provider {pinned} could not be saved.', ['pinned' => $this->pinned->key(), 'exception' => $notSaved]);

            return false;
        }

        return true;
    }
}
