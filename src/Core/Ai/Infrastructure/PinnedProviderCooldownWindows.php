<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldownNotSaved;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

// The end of each window in the cache every worker shares, judged against the clock, so all reviewers wait for the same pinned provider together.
#[AsAlias(PinnedProviderCooldowns::class)]
final readonly class PinnedProviderCooldownWindows implements PinnedProviderCooldowns
{
    private const string PREFIX = 'pinned-provider-cooldown-';

    private const int MICROSECONDS = 1000000;

    public function __construct(
        #[Autowire(service: AiServices::ACCOUNT_COOLDOWNS)]
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    public function coolDown(PinnedProvider $provider, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $key = $this->keyOf($provider);
        $until = $this->clock->now()->modify(sprintf('+%d seconds', min(AccountCooldowns::LONGEST_SECONDS, $seconds)));

        // Under the lock every worker takes, because two answers in flight could otherwise replace a later end with an earlier one.
        $lock = $this->locks->createLock($key);
        $lock->acquire(true);

        try {
            $running = $this->endOf($key);
            if ($running instanceof DateTimeImmutable && $running >= $until) {
                return;
            }

            if (!$this->pool->save($this->pool->getItem($key)->set($until))) {
                throw new PinnedProviderCooldownNotSaved($provider);
            }
        } finally {
            $lock->release();
        }
    }

    // The item has no expiry and the clock alone decides, because the cache would judge an expiry by the system time; one item per pinned provider, overwritten by a later window, so they do not pile up.
    public function isCoolingDown(PinnedProvider $provider): bool
    {
        $until = $this->endOf($this->keyOf($provider));

        return $until instanceof DateTimeImmutable && $this->clock->now() < $until;
    }

    // Rounded up from the stored end, so 1.2 seconds left is a wait of 2 and a full day read at once is still at most AccountCooldowns::LONGEST_SECONDS.
    public function secondsUntilFree(PinnedProvider $provider): int
    {
        $until = $this->endOf($this->keyOf($provider));
        if (!$until instanceof DateTimeImmutable) {
            return 0;
        }

        // In whole microseconds, because a float of the timestamp loses them and could round a full day up to a second more.
        $now = $this->clock->now();
        $left = ($until->getTimestamp() - $now->getTimestamp()) * self::MICROSECONDS + (int) $until->format('u') - (int) $now->format('u');

        return 0 < $left ? intdiv($left + self::MICROSECONDS - 1, self::MICROSECONDS) : 0;
    }

    private function endOf(string $key): ?DateTimeImmutable
    {
        $until = $this->pool->getItem($key)->get();

        return $until instanceof DateTimeImmutable ? $until : null;
    }

    // Hashed, because a router provider name comes from a model id and may hold characters PSR-6 reserves in keys.
    private function keyOf(PinnedProvider $provider): string
    {
        return self::PREFIX.hash('xxh128', $provider->key());
    }
}
