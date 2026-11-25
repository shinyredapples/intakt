<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeZone;
use Symfony\Component\Clock\NativeClock;

// An hour after the wall clock, so a recorded token is valid both for the readers on a fixed test clock, all set before the test runs, and for the container, whose clock is the wall clock.
final class RecordedTokenExpiry
{
    public static function anHourFromNow(): string
    {
        return new NativeClock()->now()->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
