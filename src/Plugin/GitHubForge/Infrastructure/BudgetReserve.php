<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Intakt\Core\Watcher\Contract\WatchTick;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

// Asked by routine reads only, so the last share of an installation's hour stays for the calls a merge, a review or a notice needs.
final readonly class BudgetReserve
{
    public function __construct(
        #[Autowire(service: GitHubForgeServices::RATE_LIMITS)]
        private CacheItemPoolInterface $pool,
        private RateLimitReadings $readings,
        private InstallationTokens $tokens,
        private ClockInterface $clock,
        private LockFactory $locks,
        #[Autowire(param: GitHubForgeServices::RESERVE_PERCENT)]
        private int $percent,
    ) {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException(sprintf('The GitHub reserve must be a share from 0 to 100 percent, not %d.', $percent));
        }
    }

    /**
     * @throws ForgeRateLimited
     */
    public function admitListing(): void
    {
        $installationId = $this->tokens->installationId;
        $reading = $this->readings->latest($installationId);

        if ($reading instanceof RateLimitReading && $reading->isBelowShare($this->percent)) {
            $this->takeTheListingOfThisTick($installationId, $reading->resetsAt);
        }
    }

    /**
     * @throws ForgeRateLimited
     */
    private function takeTheListingOfThisTick(string $installationId, DateTimeImmutable $resetsAt): void
    {
        $key = 'github-reserve-listing-'.hash('sha256', $installationId);
        $lock = $this->locks->createLock($key);

        // Not waited for, because a held lock means another process is taking this tick's listing.
        if (!$lock->acquire(false)) {
            throw new ForgeRateLimited($resetsAt);
        }

        try {
            $item = $this->pool->getItem($key);
            $now = $this->clock->now();
            $last = $item->get();

            // Compared with the clock because the cache expiry runs on system time.
            if ($item->isHit() && is_int($last) && $now->setTimestamp($last) > $now->modify('-'.WatchTick::INTERVAL)) {
                throw new ForgeRateLimited($resetsAt);
            }

            // Refused when unrecorded, because a slot nobody can see would admit every later listing too.
            if (!$this->pool->save($item->set($now->getTimestamp())->expiresAfter(DateInterval::createFromDateString(WatchTick::INTERVAL)))) {
                throw new ForgeRateLimited($resetsAt);
            }
        } finally {
            $lock->release();
        }
    }
}
