<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
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

    public function isCoolingDown(Account $account): bool
    {
        return 0 === $this->window($account)->getRemainingTokens();
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
