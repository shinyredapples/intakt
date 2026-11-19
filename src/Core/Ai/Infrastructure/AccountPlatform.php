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

    // A spent plan's limit comes without a reset (CodexAccountFailure), so only it waits for the plan's measured reset; the pin cools in a finally block after the account, so neither failing store costs the other its window.
    private function coolDownLimited(?int $retryAfter): void
    {
        try {
            if (null === $retryAfter && $this->account->provider->spendsAPlan()) {
                $this->cooldowns->coolDownUntilPlanResets($this->account);
            } else {
                $this->cooldowns->coolDown($this->account, $retryAfter);
            }
        } finally {
            $this->coolDownPinned($retryAfter);
        }
    }

    // Symfony AI's generic bridge throws its 429 without the response's Retry-After (symfony/ai#2913, noted in upstream/ai-failover-platform-deferred-rate-limit), so the header is read from the raw answer: delta-seconds, or an HTTP date judged against the clock; null when there is none or it does not parse, so the default minute applies.
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

        if (1 === preg_match('~^\d{1,9}\z~', $value)) {
            return max(1, (int) $value);
        }

        $at = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));

        return false === $at ? null : max(1, $at->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    // The pinned provider keeps its own window, which alone says how long the call waits for it (AccountFailoverPlatform); logged instead of thrown, because the provider's 429 must still reach the caller and the account still cools down.
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
