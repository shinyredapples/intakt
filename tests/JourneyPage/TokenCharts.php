<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\FigureKind;

// Only the measured tokens are drawn, because a reservation is an estimate and a total over both would mix kinds.
// The days come from the usage file alone and the merges from the counts kept for it, so the token charts change only when the usage file does.
final readonly class TokenCharts
{
    public const string PER_MERGE = 'tokens-per-merge';
    public const string USAGE = "the providers' usage answers, summed per day from the `tokens` field of `docs/journey/usage.jsonl`";
    public const string RESERVED = 'the reservations of steps that were killed before they reported usage, from the `reserved` field of `docs/journey/usage.jsonl` and never added to the measured tokens';

    /**
     * @param array<string, int> $mergesPerDay
     *
     * @return list<DailyChart>
     */
    public function charts(Usage $usage, array $mergesPerDay): array
    {
        $days = new DayRange()->days(array_map(static fn (TokenUsage $row): DateTimeImmutable => $row->day, $usage->rows));

        $measured = new FigureSource(FigureKind::Measured, self::USAGE);
        $perMerge = new FigureSource(FigureKind::Measured, "the day's tokens from ".self::USAGE.', divided by the pull requests merged that day, counted from the GitHub API');

        return [
            new DailyChart('tokens-by-lane', 'Tokens per day by lane', ChartForm::StackedBars, $days, $this->byLane($usage, $days), $measured),
            new DailyChart('tokens-by-account', 'Tokens per day by account', ChartForm::StackedBars, $days, $this->byAccount($usage, $days), $measured),
            new DailyChart(self::PER_MERGE, 'Tokens per merged pull request', ChartForm::Line, $days, [$this->perMerge($usage, $mergesPerDay, $days)], $perMerge),
        ];
    }

    /**
     * @param list<DateTimeImmutable> $days
     *
     * @return list<ChartSeries>
     */
    private function byLane(Usage $usage, array $days): array
    {
        $series = [];

        foreach (UsageVendor::cases() as $vendor) {
            foreach (UsageLane::cases() as $lane) {
                $series[] = $this->series($vendor->label().', '.$lane->label(), array_filter($usage->rows, static fn (TokenUsage $row): bool => $row->vendor === $vendor && $row->lane === $lane), $days);
            }
        }

        return array_values(array_filter($series));
    }

    /**
     * @param list<DateTimeImmutable> $days
     *
     * @return list<ChartSeries>
     */
    private function byAccount(Usage $usage, array $days): array
    {
        $series = array_map(fn (UsageAccount $account): ?ChartSeries => $this->series($account->label(), array_filter($usage->rows, static fn (TokenUsage $row): bool => $row->account === $account), $days), UsageAccount::cases());

        return array_values(array_filter($series));
    }

    /**
     * @param array<string, int>      $merges
     * @param list<DateTimeImmutable> $days
     */
    private function perMerge(Usage $usage, array $merges, array $days): ChartSeries
    {
        $tokens = $this->tokensPerDay($usage->rows);
        $values = array_map(static fn (DateTimeImmutable $day): ?int => isset($merges[$day->format('Y-m-d')]) ? intdiv($tokens[$day->format('Y-m-d')] ?? 0, $merges[$day->format('Y-m-d')]) : null, $days);

        return new ChartSeries('Tokens per merge', $values);
    }

    /**
     * @param array<int, TokenUsage>  $rows
     * @param list<DateTimeImmutable> $days
     */
    private function series(string $label, array $rows, array $days): ?ChartSeries
    {
        if ([] === $rows) {
            return null;
        }

        $tokens = $this->tokensPerDay($rows);

        return new ChartSeries($label, array_map(static fn (DateTimeImmutable $day): int => $tokens[$day->format('Y-m-d')] ?? 0, $days));
    }

    /**
     * @param array<int, TokenUsage> $rows
     *
     * @return array<string, int>
     */
    private function tokensPerDay(array $rows): array
    {
        $tokens = [];

        foreach ($rows as $row) {
            $tokens[$row->day->format('Y-m-d')] = ($tokens[$row->day->format('Y-m-d')] ?? 0) + $row->tokens;
        }

        return $tokens;
    }
}
