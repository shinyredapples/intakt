<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class JourneyDates
{
    private const string HACKATHON_YEAR = '2026';

    // The page's own year is left out, as the hand-written page did.
    public static function short(DateTimeImmutable $moment): string
    {
        return self::HACKATHON_YEAR === $moment->format('Y') ? $moment->format('m-d') : $moment->format('Y-m-d');
    }
}
