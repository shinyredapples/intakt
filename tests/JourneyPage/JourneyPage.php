<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Symfony\Component\Clock\ClockInterface;

final readonly class JourneyPage
{
    public function __construct(
        private GitHubReader $github,
        private UsageReader $usage,
        private ClockInterface $clock,
    ) {
    }

    public function regenerated(string $page, string $usageFile): string
    {
        $issues = $this->github->issues();
        $glance = new GlanceTable($this->clock->now())->region($issues, $this->github->pullRequests(), $this->usage->tokensIn($usageFile));
        $milestones = new MilestoneTable()->region($this->github->milestones(), $issues);

        return new MarkedRegions()->replaced($page, [$glance, $milestones]);
    }
}
