<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

// From the clock of the code that reads the token, so the recorded token is fresh for that reader whatever the wall clock shows.
final class RecordedTokenExpiry
{
    public static function anHourAfter(ClockInterface $readerClock): string
    {
        return $readerClock->now()->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
