<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

// The table holds the same numbers as the chart, so a reader who cannot tell its colors apart, or cannot see it, misses nothing.
final readonly class ChartRegion
{
    public function region(DailyChart $chart): PageRegion
    {
        $header = '| Day | '.implode(' | ', array_map(static fn (ChartSeries $series): string => $chart->labeled($series->label), $chart->series)).' |';
        $rule = '|---|'.str_repeat('---|', count($chart->series));
        $rows = array_map(fn (int $day, DateTimeImmutable $date): string => '| '.JourneyDates::short($date).' | '.implode(' | ', $this->cells($chart, $day)).' |', array_keys($chart->days), $chart->days);

        return new PageRegion($chart->name, implode("\n", [
            '!['.$chart->caption().'](journey/'.$chart->name.'.svg)',
            '',
            $chart->source->line(),
            '',
            '<details markdown="1"><summary>'.$chart->caption().', as a table</summary>',
            '',
            $header,
            $rule,
            ...$rows,
            '',
            '</details>',
        ]));
    }

    /**
     * @return list<string>
     */
    private function cells(DailyChart $chart, int $day): array
    {
        return array_map(static fn (ChartSeries $series): string => null === $series->values[$day] ? 'none' : number_format($series->values[$day]), $chart->series);
    }
}
