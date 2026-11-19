<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The end of each window in the cache every worker shares, judged against the clock, so all reviewers wait for the same pinned provider together.
#[AsAlias(PinnedProviderCooldowns::class)]
final readonly class PinnedProviderCooldownWindows implements PinnedProviderCooldowns
{
    private const string PREFIX = 'pinned-provider-cooldown-';

    public function __construct(
        #[Autowire(service: AiServices::ACCOUNT_COOLDOWNS)]
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
    ) {
    }

    // No check guards the write, since the newest window wins, so no lock is taken.
    public function coolDown(PinnedProvider $provider, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $until = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));
        $this->pool->save($this->pool->getItem($this->keyOf($provider))->set($until));
    }

    // The item has no expiry and the clock alone decides, because the cache would judge an expiry by the system time; one item per pinned provider, overwritten by the next window, so they do not pile up.
    public function isCoolingDown(PinnedProvider $provider): bool
    {
        $until = $this->pool->getItem($this->keyOf($provider))->get();

        return $until instanceof DateTimeImmutable && $this->clock->now() < $until;
    }

    // Hashed, because a router provider name comes from a model id and may hold characters PSR-6 reserves in keys.
    private function keyOf(PinnedProvider $provider): string
    {
        return self::PREFIX.hash('xxh128', $provider->key());
    }
}
