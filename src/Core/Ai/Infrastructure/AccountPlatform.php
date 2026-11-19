<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use DateTimeImmutable;
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
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

// Excluded from the container because AccountFailoverPlatform builds one per call around an account's own platform.
#[Exclude]
final readonly class AccountPlatform implements PlatformInterface
{
    // The same minute an account waits after a limit without a reset (AccountCooldownWindows), so a pinned provider is tried again as soon as its account would be.
    public const int PINNED_PROVIDER_COOLDOWN_SECONDS = 60;

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
        // Asked again as the call is sent, because another worker can cool the pinned provider down after the failover chose this account; not counted as tried, so the call ends as every account cooling down, not as unreachable.
        if ($this->pinned instanceof PinnedProvider && $this->pinnedCooldowns->isCoolingDown($this->pinned)) {
            $this->failures->skipped($this->account);

            throw new PinnedProviderSkipped($this->pinned);
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
        $result->onError($this->failed(...));
        // A limit that arrives while the stream is read never reaches onError, so the stream reports it too.
        $result->onConvert(fn (ResultInterface $answer): ResultInterface => $this->followAnswer($answer, $sentAt));

        return $result;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }

    // A streamed answer clears the account's reported error only once it was read whole, because a stream can still fail after it converted.
    private function followAnswer(ResultInterface $result, DateTimeImmutable $sentAt): ResultInterface
    {
        if (!$result instanceof StreamResult) {
            $this->cooldowns->answered($this->account, $sentAt);

            return $result;
        }

        $result->addListener(new AccountStreamFailures($this->failed(...)));
        $result->addListener(new AccountStreamAnswered(fn () => $this->cooldowns->answered($this->account, $sentAt)));

        return $result;
    }

    private function failed(Throwable $failure): void
    {
        // Once the answer has started, StreamedAnswer wraps the provider's exception, so the cooldown looks through to it.
        $cause = $failure instanceof IncompleteStreamException ? $failure->getPrevious() ?? $failure : $failure;
        $coolDown = $this->coolDownAfter($cause);

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
    private function coolDownAfter(Throwable $failure): ?Closure
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => fn () => $this->coolDownLimited($failure->getRetryAfter()),
            // A revoked or wrong key will not work on the next call either, so the account is out like a limited one and the call goes elsewhere; remembered as a rejection so no call waits for it.
            $failure instanceof AuthenticationException => fn () => $this->cooldowns->coolDownRejected($this->account),
            $failure instanceof RetryableExceptionInterface => fn () => $this->cooldowns->coolDown($this->account, null),
            default => null,
        };
    }

    // A spent subscription plan is raised as a limit without a reset (CodexAccountFailure), so only that limit waits for the plan's measured reset; any other failure keeps its own wait.
    private function coolDownLimited(?int $retryAfter): void
    {
        $waitsForThePlan = null === $retryAfter && $this->account->provider->spendsAPlan();
        if ($waitsForThePlan) {
            $this->cooldowns->coolDownUntilPlanResets($this->account);
        }

        if (!$this->pinned instanceof PinnedProvider) {
            if (!$waitsForThePlan) {
                $this->cooldowns->coolDown($this->account, $retryAfter);
            }

            return;
        }

        $seconds = min(AccountCooldownWindows::LONGEST_SECONDS, $retryAfter ?? self::PINNED_PROVIDER_COOLDOWN_SECONDS);
        // The account cools down too, because AccountLimitedVendor reads the wait from the account cooldowns alone; until at least the pin's end and written before the pin, because a 429 in flight can extend a pin whose account already has a shorter window, and no reader may see a pin outlive its account.
        $this->cooldowns->coolDownAtLeast($this->account, $seconds);
        $this->coolDownPinned($this->pinned, $seconds);
    }

    // Logged instead of thrown, because the provider's 429 must still reach the failover, which then tries the next account.
    private function coolDownPinned(PinnedProvider $pinned, int $seconds): void
    {
        try {
            $this->pinnedCooldowns->coolDown($pinned, $seconds);
        } catch (PinnedProviderCooldownNotSaved $notSaved) {
            $this->logger->warning('The cooldown of pinned provider {pinned} could not be saved.', ['pinned' => $pinned->key(), 'exception' => $notSaved]);
        }
    }
}
