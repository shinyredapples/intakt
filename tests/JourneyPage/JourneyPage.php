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
        $usageDigest = hash_file('sha256', $usageFile);

        if (false === $usageDigest) {
            throw new JourneyInputFailed('cannot read '.$usageFile);
        }

        $merges = new CountedMerges()->keptOn($page, $usageDigest) ?? new CountedMerges()->countedFor($usage, $pullRequests);
        $charts = [...new TokenCharts()->charts($usage, $merges), ...new IssueCharts()->charts($issues)];
        $glance = new GlanceTable($this->clock->now())->region($issues, $pullRequests, $usage);
        $milestones = new MilestoneTable()->region($this->github->milestones(), $issues);
        $countedMerges = new CountedMerges()->line($usageDigest, $merges);
        $chartRegions = array_map(fn (DailyChart $chart): PageRegion => $this->region($chart, $countedMerges), $charts);
        $regenerated = new MarkedRegions()->replaced($page, [$glance, ...$chartRegions, $milestones]);
        $timeline = new TimelineSvg()->svg(new MermaidTimeline()->in($page));

        return new JourneyOutput(
            $regenerated,
            [...array_combine(array_map(static fn (DailyChart $chart): string => $chart->name, $charts), array_map($this->drawn(...), $charts)), 'timeline' => $timeline],
        );
    }

    // The counts go inside the markers of the chart that uses them, because only marked regions may change.
    private function region(DailyChart $chart, string $countedMerges): PageRegion
    {
        $region = new ChartRegion()->region($chart);

        return TokenCharts::PER_MERGE === $chart->name ? new PageRegion($region->name, $region->text."\n".$countedMerges) : $region;
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
