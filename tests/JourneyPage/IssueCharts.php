<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

// The days run from the first issue to the last creation or closing, not to today, so a rerun on a later day draws the same chart.
final readonly class IssueCharts
{
    /**
     * @param list<Issue> $issues
     *
     * @return list<DailyChart>
     */
    public function charts(array $issues): array
    {
        $created = array_map(static fn (Issue $issue): DateTimeImmutable => $issue->created, $issues);
        $closed = array_values(array_filter(array_map(static fn (Issue $issue): ?DateTimeImmutable => $issue->closed, $issues)));
        $days = new DayRange()->days([...$created, ...$closed]);
        $createdPerDay = $this->perDay($created, $days);
        $closedPerDay = $this->perDay($closed, $days);

        return [
            new DailyChart('issues-per-day', 'Issues created and closed per day', ChartForm::GroupedBars, $days, [new ChartSeries('Created', $createdPerDay), new ChartSeries('Closed', $closedPerDay)]),
            new DailyChart('open-issues', 'Open issues per day', ChartForm::Line, $days, [new ChartSeries('Open issues', $this->openAtEndOfDay($createdPerDay, $closedPerDay))]),
        ];
    }

    /**
     * @param list<DateTimeImmutable> $moments
     * @param list<DateTimeImmutable> $days
     *
     * @return list<int>
     */
    private function perDay(array $moments, array $days): array
    {
        $counts = array_count_values(array_map(static fn (DateTimeImmutable $moment): string => $moment->format('Y-m-d'), $moments));

        return array_map(static fn (DateTimeImmutable $day): int => $counts[$day->format('Y-m-d')] ?? 0, $days);
    }

    /**
     * @param list<int> $created
     * @param list<int> $closed
     *
     * @return list<int>
     */
    private function openAtEndOfDay(array $created, array $closed): array
    {
        $open = 0;

        return array_map(static function (int $new, int $done) use (&$open): int {
            $open += $new - $done;

            return $open;
        }, $created, $closed);
    }
}
