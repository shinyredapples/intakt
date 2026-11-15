<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class DailyChart
{
    /**
     * @param list<DateTimeImmutable> $days
     * @param list<ChartSeries>       $series
     */
    public function __construct(
        public string $name,
        public string $title,
        public ChartForm $form,
        public array $days,
        public array $series,
    ) {
    }
}
