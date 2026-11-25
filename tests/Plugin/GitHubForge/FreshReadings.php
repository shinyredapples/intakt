<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// A store of its own for each test, so no pause or reading leaks into another; it expires by the test clock, because a reading expires at GitHub's reset and the system time passes a fixed test moment.
final class FreshReadings
{
    public const int RESERVE_PERCENT = 20;

    public static function at(ClockInterface $clock): RateLimitReadings
    {
        return new RateLimitReadings(new ArrayAdapter(clock: $clock), $clock, new LockFactory(new InMemoryStore()));
    }

    public static function reserveOf(RateLimitReadings $readings, InstallationTokens $tokens, ClockInterface $clock): BudgetReserve
    {
        return new BudgetReserve(new ArrayAdapter(clock: $clock), $readings, $tokens, $clock, new LockFactory(new InMemoryStore()), self::RESERVE_PERCENT);
    }
}
