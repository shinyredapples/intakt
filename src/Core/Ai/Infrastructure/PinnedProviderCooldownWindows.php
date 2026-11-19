<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
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
        $until = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));

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

    // Hashed, because a router provider name comes from a model id and may hold characters PSR-6 reserves in keys.
    private function keyOf(PinnedProvider $provider): string
    {
        return self::PREFIX.hash('xxh128', $provider->key());
    }

    private function endOf(string $key): ?DateTimeImmutable
    {
        $until = $this->pool->getItem($key)->get();

        return $until instanceof DateTimeImmutable ? $until : null;
    }
}
