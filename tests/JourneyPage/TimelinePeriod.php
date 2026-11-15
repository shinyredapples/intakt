<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class TimelinePeriod
{
    /**
     * @param list<string> $events
     */
    public function __construct(public string $when, public array $events)
    {
    }
}
