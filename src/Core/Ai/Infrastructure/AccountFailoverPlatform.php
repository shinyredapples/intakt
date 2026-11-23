<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryEndpointUnreachable;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Throwable;

// The accounts of one provider in the owner's order; excluded from the container because config/packages/ai.php defines one per provider with its accounts.
#[Exclude]
final readonly class AccountFailoverPlatform implements PlatformInterface
{
    private ModelWaits $modelWaits;

    /**
     * @param non-empty-array<string, PlatformInterface> $platforms
     */
    public function __construct(
        private Provider $provider,
        private array $platforms,
        private AccountCooldowns $cooldowns,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private PinnedProviderCooldowns $pinnedCooldowns,
    ) {
        $this->modelWaits = new ModelWaits($provider, array_keys($platforms), $cooldowns, $pinnedCooldowns);
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $failures = new SendFailures();
        $routerProvider = $this->modelWaits->routerProviderOf($model);
        $waits = $this->waits($routerProvider);
        $free = $this->freeAccounts($waits, $failures, $routerProvider);
        if ([] === $free) {
            throw $this->outOfAccounts($waits, $failures);
        }

        $this->recordSwitch($free);

        try {
            $answer = $this->failover($free)->invoke($model, $input, $options);
        } catch (RuntimeException $everyAccountFailed) {
            throw $this->typed($everyAccountFailed, $failures, $routerProvider);
        }

        return $this->typedWhenRead($answer, fn (Throwable $failure): Throwable => $failure instanceof RateLimitExceededException ? $this->limitedWhenRead($failure, $failures, $routerProvider) : $this->rejectedWhenRead($answer, $failures, $routerProvider), $options);
    }

    // The same read invoke() decides by, so tier selection never picks a model whose every account waits for its pin (issue #2860).
    public function modelWaits(): ModelWaits
    {
        return $this->modelWaits;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return array_first($this->platforms)->getModelCatalog();
    }

    // Through ModelWaits, because tier selection must read the same values that decide which accounts are free and how long the call waits.
    /**
     * @return array<string, int>
     */
    private function waits(?string $routerProvider): array
    {
        return $this->modelWaits->perAccount($routerProvider);
    }

    /**
     * @param array<string, int> $waits
     *
     * @return array<string, AccountPlatform>
     */
    private function freeAccounts(array $waits, SendFailures $failures, ?string $routerProvider): array
    {
        $free = [];

        foreach ($this->platforms as $name => $platform) {
            if (0 === $waits[$name]) {
                $account = new Account($this->provider, $name);
                // Every free account is read again right before it sends, the first one too, because the read that found it free was made before the scan read the accounts after it and before an earlier account was tried.
                $free[$name] = new AccountPlatform($account, $platform, $this->cooldowns, $this->clock, $this->logger, $failures, $this->pinnedCooldowns, $this->pinnedOf($account, $routerProvider), $this->modelWaits);
            }
        }

        return $free;
    }

    private function pinnedOf(Account $account, ?string $routerProvider): ?PinnedProvider
    {
        return null === $routerProvider ? null : new PinnedProvider($account, $routerProvider);
    }

    // When every account of the provider rejected its key, in this call or in an earlier one it still cools down from, there is nothing to wait for, so a rejection is thrown; never the provider's own, because its message can echo a key and the judging's failure listener logs what it gets. The wait is the shortest of the waits that just found no account free, never a second read, so it is at least a second (see waits()); the longest window is listed too, so min() always has a value.
    /**
     * @param array<string, int> $waits
     */
    private function outOfAccounts(array $waits, SendFailures $failures): Throwable
    {
        if ($this->isEveryKeyRejected($failures)) {
            return new EveryKeyRejected($this->provider);
        }

        return new EveryAccountCoolingDown($this->provider, min([AccountCooldowns::LONGEST_SECONDS, ...$waits]));
    }

    /**
     * @param non-empty-array<string, AccountPlatform> $free
     */
    private function recordSwitch(array $free): void
    {
        $first = array_key_first($this->platforms);
        $next = array_key_first($free);
        if ($first === $next) {
            return;
        }

        $this->logger->info('Account {first} of {provider} is cooling down, so the call goes to account {next}.', ['provider' => $this->provider->value, 'first' => $first, 'next' => $next]);
    }

    // Judged across calls through the shared cooldowns, because two accounts whose rejections land a moment apart would otherwise each be free in turn and the call would wait forever (one worker's rejection beside another's).
    private function isEveryKeyRejected(SendFailures $failures): bool
    {
        foreach (array_keys($this->platforms) as $name) {
            $account = new Account($this->provider, $name);
            if (!$failures->wasRejected($account) && !$this->cooldowns->isRejected($account)) {
                return false;
            }
        }

        return true;
    }

    // Built per call over the free accounts only, because FailoverPlatform keys its limiter by platform class, which every account of a provider shares, and calls a failed platform again on the next call (upstream/ai-failover-platform-retries-a-failed-platform).
    /**
     * @param non-empty-array<string, AccountPlatform> $free
     */
    private function failover(array $free): FailoverPlatform
    {
        $neverLimits = new RateLimiterFactory(['id' => 'account-failover', 'policy' => 'no_limit'], new InMemoryStorage());

        // A silent logger, because FailoverPlatform logs the provider's message, which can echo a key; AccountPlatform logs each failure by class instead.
        return new FailoverPlatform(array_values($free), $neverLimits, $this->clock, new NullLogger());
    }

    // FailoverPlatform's own error names no cause, so a provider whose accounts all cooled down during the call says so like one that was out before it. The unreachable case is a guard for a platform that fails while sending (the HTTP client reports a failed name lookup only when the answer is read): judged over the accounts this call was sent to, because one whose cooldown ended during the call was never tried, and typed so the vendor retries the reviewer like an unreachable endpoint.
    private function typed(RuntimeException $everyAccountFailed, SendFailures $failures, ?string $routerProvider): Throwable
    {
        if ($failures->isEveryTriedUnreachable()) {
            return new EveryEndpointUnreachable($this->provider);
        }

        $waits = $this->waits($routerProvider);
        if (!in_array(0, $waits, true)) {
            return $this->outOfAccounts($waits, $failures);
        }

        // A wait of a second instead of the failover's error, because the vendor counts that error as a failure while an account that freed up during the call and was never sent to can take the retry (issue #2860).
        return $this->freedUntried($waits, $failures) && $failures->isEveryTriedLimited() ? new EveryAccountCoolingDown($this->provider, 1) : $everyAccountFailed;
    }

    /**
     * @param array<string, int> $waits
     */
    private function freedUntried(array $waits, SendFailures $failures): bool
    {
        return array_any($waits, fn ($wait, $name) => 0 === $wait && !$failures->wasTried(new Account($this->provider, $name)));
    }

    // With a free account left the rejection is thrown as KeyRejected, never as the provider's own, because its message can echo a key and the judging's failure listener logs what it gets; FailoverPlatform already hides the provider's own when the key is rejected as the call is sent.
    private function rejectedWhenRead(DeferredResult $answer, SendFailures $failures, ?string $routerProvider): Throwable
    {
        $waits = $this->waits($routerProvider);
        if (in_array(0, $waits, true)) {
            return new KeyRejected($this->accountThatAnswered($answer));
        }

        return $this->outOfAccounts($waits, $failures);
    }

    // A 429 read from the answer has already cooled its pin (AccountPlatform), so with no account left the call ends with that wait at once and the first redelivery already waits for it, instead of the provider's 429, which Symfony AI's bridge reads without its Retry-After (issue #2861). With an account still free the 429 stays as it is.
    private function limitedWhenRead(RateLimitExceededException $limited, SendFailures $failures, ?string $routerProvider): Throwable
    {
        $waits = $this->waits($routerProvider);

        return in_array(0, $waits, true) ? $limited : $this->outOfAccounts($waits, $failures);
    }

    // A key rejected when the answer is read follows the same rule as one rejected as the call is sent, because an account cooling down from a limit beside it is free again after its wait.
    /**
     * @param Closure(Throwable): Throwable $rejected
     * @param array<string, mixed> $options
     */
    private function typedWhenRead(DeferredResult $answer, Closure $rejected, array $options): DeferredResult
    {
        $converter = new TypedRejectionConverter($answer, $rejected);
        $typed = new DeferredResult($converter, $answer->getRawResult(), $options);
        $typed->getMetadata()->set($answer->getMetadata()->all());

        return $typed;
    }

    // Matched against the provider's accounts rather than parsed, so the shape of an account key stays Account's own.
    private function accountThatAnswered(DeferredResult $answer): Account
    {
        $key = $answer->getMetadata()->get(Account::METADATA);
        foreach (array_keys($this->platforms) as $name) {
            $account = new Account($this->provider, $name);
            if ($account->key() === $key) {
                return $account;
            }
        }

        throw new LogicException(sprintf('The answer names no account of provider "%s".', $this->provider->value));
    }
}
