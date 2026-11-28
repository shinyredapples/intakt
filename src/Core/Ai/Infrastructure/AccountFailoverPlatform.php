<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryEndpointUnreachable;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountRanking;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
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

// The accounts of one provider, listed in the owner's order and ranked by measured room at every call; excluded from the container because config/packages/ai.php defines one per provider with its accounts and the ranking.
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
        private AccountRanking $ranking,
    ) {
        $this->modelWaits = new ModelWaits($provider, array_keys($platforms), $cooldowns, $pinnedCooldowns);
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $failures = new SendFailures();
        $routerProvider = $this->modelWaits->routerProviderOf($model);
        $waits = $this->waits($routerProvider);
        $ranked = $this->startable($waits);
        $this->ranking->record($this->provider, $this->pickOf($ranked, $waits));
        $free = $this->freeAccounts($ranked->inOrder, $failures, $routerProvider);
        if ([] === $free) {
            throw $this->outOfAccounts($ranked, $waits, $failures);
        }

        $this->recordSwitch($free);

        try {
            $answer = $this->failover($free)->invoke($model, $input, $options);
        } catch (RuntimeException $everyAccountFailed) {
            throw $this->typed($everyAccountFailed, $failures, $routerProvider);
        }

        return $this->typedWhenRead($answer, fn (): Throwable => $this->rejectedWhenRead($answer, $failures, $routerProvider), $options);
    }

    // 0 while an account can take the model, else the wait invoke() would report, by the same reads of the cooldowns, the pins and the ranking, so tier selection never picks a model whose every account waits for its pin (issue #2860) or is left out by measured room. Tier selection reads this only right before a start, and a start it refuses never reaches invoke(), so the refused start is recorded here; a free one is recorded by invoke().
    public function secondsUntilFree(string|Model $model): int
    {
        $waits = $this->waits($this->modelWaits->routerProviderOf($model));
        $ranked = $this->startable($waits);
        if ([] !== $ranked->inOrder) {
            return 0;
        }

        $this->ranking->record($this->provider, $this->pickOf($ranked, $waits));

        return $this->secondsUntilStartable($ranked, $waits);
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

    // Only the accounts with no cooldown are ranked, because the ranking reads measured room and holds but not this provider's cooldowns.
    /**
     * @param array<string, int> $waits
     */
    private function startable(array $waits): RankedAccounts
    {
        $accounts = [];
        foreach (array_keys($this->platforms) as $name) {
            if (0 === $waits[$name]) {
                $accounts[] = new Account($this->provider, $name);
            }
        }

        return $this->ranking->rank($accounts);
    }

    // The pick also names each account this call drops for a cooldown, because the ranking saw only the rest and the owner must see why every account was not used.
    /**
     * @param array<string, int> $waits
     */
    private function pickOf(RankedAccounts $ranked, array $waits): RankedAccounts
    {
        $now = $this->clock->now();
        $held = [];
        foreach ($this->leftOutWhileCooling($waits) as $left) {
            $held[$left->account->name] = $left;
        }

        $cooling = [];
        foreach ($waits as $name => $wait) {
            if (0 < $wait) {
                $cooling[] = new LeftOutAccount(new Account($this->provider, $name), LeftOutCause::CoolingDown, $this->endWhileCooling($now->modify(sprintf('+%d seconds', $wait)), $held[$name] ?? null));
            }
        }

        return new RankedAccounts($ranked->inOrder, [...$ranked->leftOut, ...$cooling]);
    }

    // The cooling accounts are ranked for the wait only, never for the send order, because startable() leaves them out before it ranks, so a ceiling that outlasts a cooldown would otherwise never reach the wait.
    /**
     * @param array<string, int> $waits
     *
     * @return list<LeftOutAccount>
     */
    private function leftOutWhileCooling(array $waits): array
    {
        $cooling = [];
        foreach (array_keys($this->platforms) as $name) {
            if (0 < $waits[$name]) {
                $cooling[] = new Account($this->provider, $name);
            }
        }

        return [] === $cooling ? [] : $this->ranking->rank($cooling)->leftOut;
    }

    // The later of the cooldown's end and the end of what else holds the account, unknown when that hold names none, because the account starts again only once both have ended.
    private function endWhileCooling(DateTimeImmutable $cooledUntil, ?LeftOutAccount $held): ?DateTimeImmutable
    {
        if (!$held instanceof LeftOutAccount) {
            return $cooledUntil;
        }

        return $held->resetsAt instanceof DateTimeImmutable ? max($cooledUntil, $held->resetsAt) : null;
    }

    /**
     * @param list<Account> $ranked
     *
     * @return array<string, AccountPlatform>
     */
    private function freeAccounts(array $ranked, SendFailures $failures, ?string $routerProvider): array
    {
        $free = [];

        foreach ($ranked as $account) {
            // Every free account is read again right before it sends, the first one too, because the read that found it free was made before the scan read the accounts after it and before an earlier account was tried.
            $free[$account->name] = new AccountPlatform($account, $this->platforms[$account->name], $this->cooldowns, $this->clock, $this->logger, $failures, $this->pinnedCooldowns, $this->pinnedOf($account, $routerProvider), $this->modelWaits);
        }

        return $free;
    }

    private function pinnedOf(Account $account, ?string $routerProvider): ?PinnedProvider
    {
        return null === $routerProvider ? null : new PinnedProvider($account, $routerProvider);
    }

    // When every account of the provider rejected its key, in this call or in an earlier one it still cools down from, there is nothing to wait for, so a rejection is thrown; never the provider's own, because its message can echo a key and the judging's failure listener logs what it gets.
    /**
     * @param array<string, int> $waits
     */
    private function outOfAccounts(RankedAccounts $ranked, array $waits, SendFailures $failures): Throwable
    {
        if ($this->isEveryKeyRejected($failures)) {
            return new EveryKeyRejected($this->provider);
        }

        return new EveryAccountCoolingDown($this->provider, $this->secondsUntilStartable($ranked, $waits));
    }

    // The soonest moment any account can start again. Per account the longer of its cooldown's wait, from the read that found no account free and never a second read, and its left-out end, because an account that is both cooling down and at a ceiling starts only once both have ended; then the shortest across the accounts. At least a second, so a refused call never waits 0 s, and at most the longest window, which also stands for an end the ranking cannot name.
    /**
     * @param array<string, int> $waits
     */
    private function secondsUntilStartable(RankedAccounts $ranked, array $waits): int
    {
        $leftOut = $this->leftOutWaits([...$ranked->leftOut, ...$this->leftOutWhileCooling($waits)]);
        $perAccount = [];
        foreach ($waits as $name => $wait) {
            $perAccount[] = max($wait, $leftOut[$name] ?? 0);
        }

        return max(1, min([AccountCooldowns::LONGEST_SECONDS, ...$perAccount]));
    }

    /**
     * @param list<LeftOutAccount> $leftOut
     *
     * @return array<string, int>
     */
    private function leftOutWaits(array $leftOut): array
    {
        $now = $this->clock->now()->getTimestamp();
        $waits = [];
        foreach ($leftOut as $left) {
            $waits[$left->account->name] = $left->resetsAt instanceof DateTimeImmutable ? max(1, $left->resetsAt->getTimestamp() - $now) : AccountCooldowns::LONGEST_SECONDS;
        }

        return $waits;
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

        $this->logger->info('Account {first} of {provider} is cooling down or ranks lower by measured room, so the call goes to account {next}.', ['provider' => $this->provider->value, 'first' => $first, 'next' => $next]);
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
        $ranked = $this->startable($waits);
        if ([] === $ranked->inOrder) {
            return $this->outOfAccounts($ranked, $waits, $failures);
        }

        if (!$failures->isEveryTriedLimited()) {
            return $everyAccountFailed;
        }

        // A wait of a second instead of the failover's error, because the vendor counts that error as a failure while an account that freed up during the call and was never sent to can take the retry (issue #2860); only an account the ranking still lets start counts, so one at its measured ceiling never asks for a retry it cannot take.
        if ($this->freedUntried($ranked, $failures)) {
            return new EveryAccountCoolingDown($this->provider, 1);
        }

        // Every free account was sent to and limited, so no window stands: a pin ended between its save and this read, or a store saved none (issue #3975). A pinned call then waits the shortest time its limits named, never a flat second, because a store that keeps refusing saves would otherwise let the caller send again every second against a longer reset. An unpinned call keeps the failover's error.
        $named = null === $routerProvider ? null : $this->shortestWaitNamed($waits, $failures);

        return null === $named ? $everyAccountFailed : new EveryAccountCoolingDown($this->provider, $named);
    }

    private function freedUntried(RankedAccounts $ranked, SendFailures $failures): bool
    {
        return array_any($ranked->inOrder, static fn (Account $account): bool => !$failures->wasTried($account));
    }

    // Per limited account the longer of what its limit still has to run and the window now stored for it, because another worker can have extended a window that this call's own limit names shorter. An account the call never sent to counts with its stored wait above zero, as in outOfAccounts(), so the wait does not depend on whether a limited account's window was saved. Null when no account of the call recorded a limit.
    /**
     * @param array<string, int> $waits
     */
    private function shortestWaitNamed(array $waits, SendFailures $failures): ?int
    {
        $now = $this->clock->now()->getTimestamp();
        $named = [];
        $stored = [];
        foreach (array_keys($this->platforms) as $name) {
            $account = new Account($this->provider, $name);
            $left = $failures->limitedWait($account, $now);
            if (null !== $left) {
                $named[] = max($left, $waits[$name] ?? 0);
            } elseif (!$failures->wasTried($account) && ($waits[$name] ?? 0) > 0) {
                $stored[] = $waits[$name];
            }
        }

        return [] === $named ? null : min([...$named, ...$stored]);
    }

    // With a free account left the rejection is thrown as KeyRejected, never as the provider's own, because its message can echo a key and the judging's failure listener logs what it gets; FailoverPlatform already hides the provider's own when the key is rejected as the call is sent.
    private function rejectedWhenRead(DeferredResult $answer, SendFailures $failures, ?string $routerProvider): Throwable
    {
        $waits = $this->waits($routerProvider);
        $ranked = $this->startable($waits);
        if ([] !== $ranked->inOrder) {
            return new KeyRejected($this->accountThatAnswered($answer));
        }

        return $this->outOfAccounts($ranked, $waits, $failures);
    }

    // A key rejected when the answer is read follows the same rule as one rejected as the call is sent, because an account cooling down from a limit beside it is free again after its wait.
    /**
     * @param Closure(): Throwable $rejected
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
