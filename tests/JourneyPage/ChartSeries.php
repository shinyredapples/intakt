<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class ChartSeries
{
    /**
     * @param list<?int> $values one per day of the chart, null where the day has no value
     */
    public function __construct(public string $label, public array $values)
    {
    }
}
