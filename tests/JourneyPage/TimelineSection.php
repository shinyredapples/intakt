<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class TimelineSection
{
    /**
     * @param list<TimelinePeriod> $periods
     */
    public function __construct(public string $name, public array $periods)
    {
    }
}
