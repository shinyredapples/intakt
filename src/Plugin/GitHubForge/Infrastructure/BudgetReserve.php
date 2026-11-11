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
    public function admitListing(string $project): void
    {
        $installationId = $this->tokens->installationId;
        $reading = $this->readings->latest($installationId);

        if ($reading instanceof RateLimitReading && $reading->isBelowShare($this->percent)) {
            $this->takeTheListingOfThisTick(self::slotKey($installationId, $project), $reading->resetsAt);
        }
    }

    // One slot per project, because each project's tick lists once and a shared slot would leave every project but the first unwatched until the reset.
    public static function slotKey(string $installationId, string $project): string
    {
        return 'github-reserve-listing-'.hash('sha256', strlen($installationId).':'.$installationId.strlen($project).':'.$project);
    }

    /**
     * @throws ForgeRateLimited
     */
    private function takeTheListingOfThisTick(string $key, DateTimeImmutable $resetsAt): void
    {
        $lock = $this->locks->createLock($key);

        // Not waited for, because a held lock means another process is taking this tick's listing.
        if (!$lock->acquire(false)) {
            throw new ForgeRateLimited($resetsAt);
        }

        try {
            $item = $this->pool->getItem($key);
            $now = $this->clock->now();
            $due = $item->get();
            $tickSeconds = $now->getTimestamp() - $now->modify('-'.WatchTick::INTERVAL)->getTimestamp();
            $due = $item->isHit() && is_int($due) ? $due : $now->getTimestamp();

            // Counted from when the listing was due, not when it ran, so a tick handled late (behind another project's on the serial consumer) keeps the next tick's listing and an early one adds none; the half tick is the lateness a listing may make up.
            // Compared with the clock because the cache expiry runs on system time.
            if ($now->getTimestamp() <= $due - intdiv($tickSeconds, 2)) {
                throw new ForgeRateLimited($resetsAt);
            }

            // Refused when unrecorded, because a slot nobody can see would admit every later listing too.
            if (!$this->pool->save($item->set(max($now->getTimestamp(), $due) + $tickSeconds)->expiresAfter(DateInterval::createFromDateString(WatchTick::INTERVAL)))) {
                throw new ForgeRateLimited($resetsAt);
            }
        } finally {
            $lock->release();
        }
    }
}
