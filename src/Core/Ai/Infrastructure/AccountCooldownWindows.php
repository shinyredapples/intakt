<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountError;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

// A RateLimiter window of one call per account, anchored at the vendor's reset, in a cache every worker shares, so all reviewers on an account wait together.
#[AsAlias(AccountCooldowns::class)]
final readonly class AccountCooldownWindows implements AccountCooldowns
{
    public const string POOL = 'intakt.ai.account_cooldowns';
    private const string LIMITER = 'account-cooldown';

    // The key of an account error's report, as the lock while it is reported and in the pool once it was.
    public const string REPORT_LOCK = 'account-cooldown-reported-';

    // One minute because a provider that sends no reset still gets a pause, and it is the async transport's longest wait between two tries.
    private const int SECONDS_WITHOUT_RESET = 60;

    // At most a day, so a huge reset never puts an account out of reach and the window stays inside its one-month period.
    private const int LONGEST_SECONDS = 86400;

    /**
     * @param array<string, list<string>> $accounts
     */
    public function __construct(
        #[Autowire(service: self::POOL)]
        private CacheItemPoolInterface $pool,
        private LockFactory $locks,
        private ClockInterface $clock,
        private Ledger $ledger,
        private LoggerInterface $logger,
        #[Autowire('%intakt.ai.accounts%')]
        private array $accounts,
    ) {
    }

    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
        $seconds = min(self::LONGEST_SECONDS, $resetInSeconds ?? self::SECONDS_WITHOUT_RESET);
        if ($seconds <= 0) {
            return;
        }

        $until = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));

        // Refused while a cooldown already runs, which is kept as it is, because only a call already in flight can still be limited then.
        if (!$this->windowsEndingAt($until)->create($account->key())->consume()->isAccepted()) {
            return;
        }

        $this->record($account, $until);
    }

    // The marker ends with the window that runs, the rejection's minute or a limit set before it, so the account is retried when its cooldown ends like any other.
    public function coolDownRejected(Account $account): void
    {
        $this->coolDown($account, null);

        $marker = $this->pool->getItem($this->rejectionKey($account))->set(true)->expiresAt($this->window($account)->getRetryAfter());
        $this->pool->save($marker);
    }

    // Cooled down like a limit without a reset, so an account the owner has fixed is used again within the minute; the report keeps the repeated failures from being noise.
    public function coolDownUnusable(Account $account, AccountError $error): void
    {
        $this->coolDown($account, null);
        $this->reportOnce($account, $error);
    }

    public function answered(Account $account): void
    {
        $this->pool->deleteItems(array_map(static fn (AccountError $error): string => self::reportKey($account, $error), AccountError::cases()));
    }

    public function isCoolingDown(Account $account): bool
    {
        return 0 === $this->window($account)->getRemainingTokens();
    }

    public function isRejected(Account $account): bool
    {
        return $this->pool->getItem($this->rejectionKey($account))->isHit();
    }

    public function isEveryAccountRejected(Provider $provider): bool
    {
        $names = $this->accounts[$provider->value] ?? [];

        return [] !== $names && array_all($names, fn (string $name): bool => $this->isRejected(new Account($provider, $name)));
    }

    public function secondsUntilAnAccountIsFree(Provider $provider): int
    {
        $waits = array_map(fn (string $name): int => $this->secondsUntilFree(new Account($provider, $name)), $this->accounts[$provider->value] ?? []);

        return [] === $waits ? 0 : min($waits);
    }

    // At least a second while the window runs, so a cooling account is never reported free and retried at once.
    private function secondsUntilFree(Account $account): int
    {
        $window = $this->window($account);
        if (0 < $window->getRemainingTokens()) {
            return 0;
        }

        return max(1, $window->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    // No token, so reading the window never starts or saves one.
    private function window(Account $account): RateLimit
    {
        return $this->windowsEndingAt($this->clock->now())->create($account->key())->consume(0);
    }

    // Anchored at the end so the fixed window closes at the vendor's reset; one month because it is the shortest interval the RateLimiter anchors.
    private function windowsEndingAt(DateTimeImmutable $end): RateLimiterFactory
    {
        $config = ['id' => self::LIMITER, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 month', 'anchor_at' => $end];

        return new RateLimiterFactory($config, new CacheStorage($this->pool), $this->locks);
    }

    // Beside the window in the same pool, so every worker sees why the account cools down; the account key holds a provider name and an account name the owner configured, never outside input.
    private function rejectionKey(Account $account): string
    {
        return self::LIMITER.'-rejected-'.$account->key();
    }

    // Tried without waiting, because a worker that holds the lock is reporting this very error.
    private function reportOnce(Account $account, AccountError $error): void
    {
        $lock = $this->locks->createLock(self::reportKey($account, $error));
        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->reportUnlessReported($account, $error);
        } finally {
            $lock->release();
        }
    }

    private function reportUnlessReported(Account $account, AccountError $error): void
    {
        $reported = $this->pool->getItem(self::reportKey($account, $error));
        if ($reported->isHit()) {
            return;
        }

        $this->pool->save($reported->set(true));
        $this->report($account, $error);
    }

    // The ledger event and the error log line are the owner's notice until a notifier sends it (plan T67).
    private function report(Account $account, AccountError $error): void
    {
        $notice = ['account' => $account->key(), 'error' => $error->value];
        $this->logger->error('Account {account} needs the owner: {error}.', $notice);

        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountErrorReported, json_encode($notice, JSON_THROW_ON_ERROR)));
        if (!$appended->succeeded()) {
            $this->logger->error('An account error was not recorded.', [...$notice, 'reason' => $appended->failure]);
        }
    }

    // The error's value holds no dot, so the first dot ends it and the account key cannot pass for another error's.
    private static function reportKey(Account $account, AccountError $error): string
    {
        return self::REPORT_LOCK.$error->value.'.'.$account->key();
    }

    private function record(Account $account, DateTimeImmutable $until): void
    {
        $payload = json_encode(['account' => $account->key(), 'until' => $until->format(DATE_ATOM)], JSON_THROW_ON_ERROR);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountCooledDown, $payload));

        // Logged, not thrown, because the cooldown holds without its record and failing here would hide the provider's own error.
        if (!$appended->succeeded()) {
            $this->logger->error('An account cooldown was not recorded.', ['cooldown' => $payload, 'reason' => $appended->failure]);
        }
    }
}
