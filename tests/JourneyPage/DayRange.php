<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final readonly class DayRange
{
    /**
     * @param list<DateTimeImmutable> $moments
     *
     * @return list<DateTimeImmutable>
     */
    public function days(array $moments): array
    {
        if ([] === $moments) {
            return [];
        }

        $period = new DatePeriod(min($moments)->setTime(0, 0), new DateInterval('P1D'), max($moments)->setTime(0, 0), DatePeriod::INCLUDE_END_DATE);

        return array_values(array_filter(iterator_to_array($period, false), static fn (mixed $day): bool => $day instanceof DateTimeImmutable));
    }
}
