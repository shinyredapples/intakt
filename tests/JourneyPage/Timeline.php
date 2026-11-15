<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class Timeline
{
    /**
     * @param list<TimelineSection> $sections
     */
    public function __construct(public string $title, public array $sections)
    {
    }
}
