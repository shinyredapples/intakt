<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class ChartSeries
{
    /**
     * @param list<?int> $values
     */
    public function __construct(public string $label, public array $values)
    {
    }
}
