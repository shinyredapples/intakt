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

    public function regenerated(string $page, string $usageFile): JourneyOutput
    {
        $issues = $this->github->issues();
        $pullRequests = $this->github->pullRequests();
        $usage = $this->usage->usageIn($usageFile);
        $charts = [...new TokenCharts()->charts($usage, $pullRequests), ...new IssueCharts()->charts($issues)];
        $glance = new GlanceTable($this->clock->now())->region($issues, $pullRequests, $usage->total());
        $milestones = new MilestoneTable()->region($this->github->milestones(), $issues);
        $regenerated = new MarkedRegions()->replaced($page, [$glance, ...array_map(new ChartRegion()->region(...), $charts), $milestones]);
        $timeline = new TimelineSvg()->svg(new MermaidTimeline()->in($page));

        return new JourneyOutput(
            $regenerated,
            [...array_combine(array_map(static fn (DailyChart $chart): string => $chart->name, $charts), array_map($this->drawn(...), $charts)), 'timeline' => $timeline],
        );
    }

    private function drawn(DailyChart $chart): string
    {
        return match ($chart->form) {
            ChartForm::StackedBars => new StackedBarSvg()->svg($chart),
            ChartForm::GroupedBars => new GroupedBarSvg()->svg($chart),
            ChartForm::Line => new LineSvg()->svg($chart),
        };
    }
}
