<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Closure;
use DateTimeImmutable;

// The days come from the usage file alone, so the token charts change only when the usage file does.
final readonly class TokenCharts
{
    /**
     * @param list<PullRequest> $pullRequests
     *
     * @return list<DailyChart>
     */
    public function charts(Usage $usage, array $pullRequests): array
    {
        $days = new DayRange()->days(array_map(static fn (TokenUsage $row): DateTimeImmutable => $row->day, $usage->rows));

        return [
            new DailyChart('tokens-by-lane', 'Tokens per day by lane', ChartForm::StackedBars, $days, $this->byLane($usage, $days)),
            new DailyChart('tokens-by-account', 'Tokens per day by account', ChartForm::StackedBars, $days, $this->byAccount($usage, $days)),
            new DailyChart('tokens-per-merge', 'Tokens per merged pull request', ChartForm::Line, $days, [$this->perMerge($usage, $pullRequests, $days)]),
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
                $series[] = $this->series($vendor->label().', '.$lane->label(), $usage, $days, static fn (TokenUsage $row): bool => $row->vendor === $vendor && $row->lane === $lane);
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
        $series = array_map(fn (UsageAccount $account): ?ChartSeries => $this->series($account->label(), $usage, $days, static fn (TokenUsage $row): bool => $row->account === $account), UsageAccount::cases());

        return array_values(array_filter($series));
    }

    /**
     * @param list<PullRequest>       $pullRequests
     * @param list<DateTimeImmutable> $days
     */
    private function perMerge(Usage $usage, array $pullRequests, array $days): ChartSeries
    {
        $tokens = $this->tokensPerDay($usage->rows);
        $merges = array_count_values(array_map(static fn (DateTimeImmutable $merged): string => $merged->format('Y-m-d'), array_values(array_filter(array_map(static fn (PullRequest $pullRequest): ?DateTimeImmutable => $pullRequest->merged, $pullRequests)))));
        $values = array_map(static fn (DateTimeImmutable $day): ?int => isset($merges[$day->format('Y-m-d')]) ? intdiv($tokens[$day->format('Y-m-d')] ?? 0, $merges[$day->format('Y-m-d')]) : null, $days);

        return new ChartSeries('Tokens per merge', $values);
    }

    /**
     * @param list<DateTimeImmutable>   $days
     * @param Closure(TokenUsage): bool $belongs
     */
    private function series(string $label, Usage $usage, array $days, Closure $belongs): ?ChartSeries
    {
        $rows = array_values(array_filter($usage->rows, $belongs));

        if ([] === $rows) {
            return null;
        }

        $tokens = $this->tokensPerDay($rows);

        return new ChartSeries($label, array_map(static fn (DateTimeImmutable $day): int => $tokens[$day->format('Y-m-d')] ?? 0, $days));
    }

    /**
     * @param list<TokenUsage> $rows
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
