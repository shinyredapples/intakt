<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Serializer\SerializerInterface;

// A RateLimiter window of one call per account, anchored at the vendor's reset, in a cache every worker shares, so all reviewers on an account wait together.
#[AsAlias(AccountCooldowns::class)]
final readonly class AccountCooldownWindows implements AccountCooldowns
{
    private const string LIMITER = 'account-cooldown';

    // One minute because a provider that sends no reset still gets a pause, and it is the async transport's longest wait between two tries.
    private const int SECONDS_WITHOUT_RESET = 60;

    // At most a day, so a huge reset never puts an account out of reach and the window stays inside its one-month period.
    private const int LONGEST_SECONDS = 86400;

    /**
     * @param array<string, list<string>> $accounts
     */
    public function __construct(
        #[Autowire(service: AiServices::ACCOUNT_COOLDOWNS)]
        private CacheItemPoolInterface $pool,
        private LockFactory $locks,
        private ClockInterface $clock,
        private Ledger $ledger,
        private LoggerInterface $logger,
        #[Target('stored')]
        private SerializerInterface $serializer,
        #[Autowire(param: AiServices::ACCOUNTS)]
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
        $this->reportOnce($account);
    }

    // Under the report's lock, so a report another worker makes meanwhile is never cleared; asked once before it, because almost every answer comes with nothing reported.
    public function answered(Account $account, DateTimeImmutable $sentAt): void
    {
        if (!$this->isReportedBefore($account, $sentAt)) {
            return;
        }

        $lock = $this->locks->createLock($this->reportKey($account));
        $lock->acquire(true);

        try {
            if ($this->isReportedBefore($account, $sentAt)) {
                $this->pool->deleteItem($this->reportKey($account));
            }
        } finally {
            $lock->release();
        }
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

    // Kept without an expiry, unlike the rejection, so an error that outlasts its cooldown is not reported again on each retry; only an answer clears it.
    private function reportKey(Account $account): string
    {
        return self::LIMITER.'-reported-'.$account->key();
    }

    // Strictly before, because an answer to a call sent before the report or at its moment may have been read after the error that came later.
    private function isReportedBefore(Account $account, DateTimeImmutable $sentAt): bool
    {
        $reportedAt = $this->pool->getItem($this->reportKey($account))->get();

        return $reportedAt instanceof DateTimeImmutable && $reportedAt < $sentAt;
    }

    // Tried without waiting, because a busy lock means another worker is reporting this account or clearing its report, and the next error of a cleared one is reported again.
    private function reportOnce(Account $account): void
    {
        $lock = $this->locks->createLock($this->reportKey($account));
        if (!$lock->acquire()) {
            return;
        }

        try {
            if (!$this->pool->getItem($this->reportKey($account))->isHit()) {
                $this->report($account);
            }
        } finally {
            $lock->release();
        }
    }

    private function report(Account $account): void
    {
        $reportedAt = $this->clock->now();
        $payload = $this->serializer->serialize(new AccountErrorReported($account->key(), $reportedAt), 'json');
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountErrorReported, $payload));

        // Marked only once recorded, so a report the ledger refused is made again on the next error; logged, not thrown, for the same reason as a cooldown's record.
        if (!$appended->succeeded()) {
            $this->logger->error('An account error was not reported.', ['report' => $payload, 'reason' => $appended->failure]);

            return;
        }

        $this->pool->save($this->pool->getItem($this->reportKey($account))->set($reportedAt));
    }

    private function record(Account $account, DateTimeImmutable $until): void
    {
        $payload = $this->serializer->serialize(new AccountCooledDown($account->key(), $until), 'json');
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountCooledDown, $payload));

        // Logged, not thrown, because the cooldown holds without its record and failing here would hide the provider's own error.
        if (!$appended->succeeded()) {
            $this->logger->error('An account cooldown was not recorded.', ['cooldown' => $payload, 'reason' => $appended->failure]);
        }
    }
}
