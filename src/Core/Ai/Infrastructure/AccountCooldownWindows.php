<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
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
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

// A RateLimiter window of one call per account, anchored at the vendor's reset, in a cache every worker shares, so all reviewers on an account wait together.
#[AsAlias(AccountCooldowns::class)]
final readonly class AccountCooldownWindows implements AccountCooldowns
{
    private const string LIMITER = 'account-cooldown';

    private const float SPENT_PERCENT = 100.0;

    /**
     * @param array<string, list<string>> $accounts
     */
    public function __construct(
        private MeasuredUse $measured,
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
        $this->coolDownFor($account, $resetInSeconds ?? self::SECONDS_WITHOUT_RESET);
    }

    public function coolDownUntilPlanResets(Account $account): void
    {
        $this->coolDownFor($account, $this->secondsUntilSpentWindowResets($account) ?? self::SECONDS_WITHOUT_RESET);
    }

    // The marker ends with the window that runs, the rejection's minute or a limit set before it, so the account is retried when its cooldown ends like any other.
    public function coolDownRejected(Account $account): void
    {
        $this->coolDownFor($account, self::SECONDS_WITHOUT_RESET);

        $marker = $this->pool->getItem($this->rejectionKey($account))->set(true)->expiresAt($this->window($account)->getRetryAfter());
        $this->pool->save($marker);
        $this->reportOnce($account);
    }

    // Asked before the lock is taken, because almost every answer comes with nothing reported.
    public function answered(Account $account, DateTimeImmutable $sentAt): void
    {
        if ($this->isReportedBefore($account, $sentAt)) {
            $this->clearReport($account, $sentAt);
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

    public function secondsUntilFree(Account $account): int
    {
        $window = $this->window($account);
        if (0 < $window->getRemainingTokens()) {
            return 0;
        }

        return max(1, $window->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    // Asked only for a spent plan's limit, which comes without a reset (issue #1749), so the account's measured readings say when it is usable again: the latest reset of a spent window still ahead.
    private function secondsUntilSpentWindowResets(Account $account): ?int
    {
        try {
            $usages = array_map(fn (UsageWindow $window): ?Usage => $this->measured->of($account, $window)?->usage, UsageWindow::cases());
            // Boundary: logs the failed read and keeps the default minute, because the provider's own limit must still reach the caller and the account must still pause.
        } catch (Throwable $unreadable) {
            $this->logger->warning('The measured readings of account {account} could not be read.', ['account' => $account->key(), 'exception' => $unreadable]);

            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        $waits = array_map(static fn (?Usage $usage): int => $usage instanceof Usage && $usage->usedPercent >= self::SPENT_PERCENT && $usage->resetsAt instanceof DateTimeImmutable ? $usage->resetsAt->getTimestamp() - $now : 0, $usages);
        $longest = max(0, ...$waits);

        return 0 < $longest ? $longest : null;
    }

    private function coolDownFor(Account $account, int $resetInSeconds): void
    {
        $seconds = min(self::LONGEST_SECONDS, $resetInSeconds);
        if ($seconds <= 0) {
            return;
        }

        $until = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));

        $pool = new PoolRememberingItsSave($this->pool);
        $consumed = $this->windowsEndingAt($until, $pool)->create($account->key())->consume();

        // Refused while a cooldown already runs, which is kept as it is, because only a call already in flight can still be limited then.
        if (!$consumed->isAccepted()) {
            return;
        }

        // A cache that refuses the save still accepts the token, so the account would read free while its event said it cooled: the cache's own result decides, under the limiter's lock, so another worker's window is never taken for this one.
        if (!$pool->isSaved()) {
            $this->logger->warning('The cooldown of account {account} could not be saved.', ['account' => $account->key()]);

            return;
        }

        $this->record($account, $until);
    }

    // Anchored at the end so the fixed window closes at the vendor's reset; one month because it is the shortest interval the RateLimiter anchors.
    private function windowsEndingAt(DateTimeImmutable $end, CacheItemPoolInterface $pool): RateLimiterFactory
    {
        $config = ['id' => self::LIMITER, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 month', 'anchor_at' => $end];

        return new RateLimiterFactory($config, new CacheStorage($pool), $this->locks);
    }

    // Beside the window in the same pool, so every worker sees why the account cools down; the account key holds a provider name and an account name the owner configured, never outside input.
    private function rejectionKey(Account $account): string
    {
        return self::LIMITER.'-rejected-'.$account->key();
    }

    // No token, so reading the window never starts or saves one.
    private function window(Account $account): RateLimit
    {
        return $this->windowsEndingAt($this->clock->now(), $this->pool)->create($account->key())->consume(0);
    }

    // Strictly before, because an answer to a call sent before the report or at its moment may have been read after the error that came later.
    private function isReportedBefore(Account $account, DateTimeImmutable $sentAt): bool
    {
        $reportedAt = $this->pool->getItem($this->reportKey($account))->get();

        return $reportedAt instanceof DateTimeImmutable && $reportedAt < $sentAt;
    }

    // Kept without an expiry, unlike the rejection, so an error that outlasts its cooldown is not reported again on each retry; only an answer clears it.
    private function reportKey(Account $account): string
    {
        return self::LIMITER.'-reported-'.$account->key();
    }

    // Asked again under the report's lock, because another worker may have reported a newer error since, and that report must stay.
    private function clearReport(Account $account, DateTimeImmutable $sentAt): void
    {
        $lock = $this->locks->createLock($this->reportKey($account));
        $lock->acquire(true);

        try {
            if ($this->isReportedBefore($account, $sentAt)) {
                $this->pool->deleteItem($this->reportKey($account));
            }
        } finally {
            $lock->release();
        }

        $this->reportPending($account);
    }

    // Marked before the lock is tried, so an error that finds the lock busy is left to the worker holding it, which looks again once it lets go.
    private function reportOnce(Account $account): void
    {
        $this->pool->save($this->pool->getItem($this->pendingKey($account))->set(true));
        $this->reportPending($account);
    }

    // Tried without waiting and looked at again after each release, so an error marked while the lock was held is reported by whichever worker holds it last, and never twice.
    private function reportPending(Account $account): void
    {
        $lock = $this->locks->createLock($this->reportKey($account));
        while ($this->isPending($account) && $lock->acquire()) {
            try {
                $this->reportIfStillPending($account);
            } finally {
                $lock->release();
            }
        }
    }

    private function isPending(Account $account): bool
    {
        return $this->pool->getItem($this->pendingKey($account))->isHit();
    }

    // Asked again under the lock, because another worker may have reported the pending error since the look before the lock, and an answer may have cleared that report already.
    private function reportIfStillPending(Account $account): void
    {
        if (!$this->isPending($account)) {
            return;
        }

        $this->pool->deleteItem($this->pendingKey($account));
        if (!$this->pool->getItem($this->reportKey($account))->isHit()) {
            $this->report($account);
        }
    }

    // Named apart from the report so its key never contains the report's key.
    private function pendingKey(Account $account): string
    {
        return self::LIMITER.'-pending-'.$account->key();
    }

    private function report(Account $account): void
    {
        $reportedAt = $this->clock->now();
        $payload = $this->serializer->serialize(new AccountErrorReported($account->key(), $reportedAt), JsonEncoder::FORMAT);
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
        $payload = $this->serializer->serialize(new AccountCooledDown($account->key(), $until), JsonEncoder::FORMAT);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountCooledDown, $payload));

        // Logged, not thrown, because the cooldown holds without its record and failing here would hide the provider's own error.
        if (!$appended->succeeded()) {
            $this->logger->error('An account cooldown was not recorded.', ['cooldown' => $payload, 'reason' => $appended->failure]);
        }
    }
}
