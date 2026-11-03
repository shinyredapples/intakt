<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// A store of its own for each test, so no pause or reading leaks from one test into another.
final class FreshReadings
{
    public static function at(ClockInterface $clock): RateLimitReadings
    {
        return new RateLimitReadings(new ArrayAdapter(), $clock, new LockFactory(new InMemoryStore()));
    }
}
