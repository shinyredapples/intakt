<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\Cost;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Drawn only once rounds start on this many days, because a trend of one or two points is no trend and an empty grid says nothing.
#[Exclude]
final readonly class ReviewsPerDay
{
    public const int MINIMUM_DAYS = 3;

    // One unit of a cost bar is a ten-thousandth of a dollar, the precision a cost reading shows, so the bars stay whole numbers.
    private const int COST_UNIT = 10000;

    // Keyed by UTC start day and oldest first, because the day axis of both charts must read left to right in time.
    /**
     * @param array<string, list<ReviewedRound>> $days
     * @param array<string, string>              $names
     */
    private function __construct(private array $days, private array $names)
    {
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    public static function of(array $rounds): ?self
    {
        $days = [];
        $starts = [];

        foreach ($rounds as $round) {
            $start = $round->startedAt->setTimezone(new DateTimeZone('UTC'));
            $days[$start->format('Y-m-d')][] = $round;
            $starts[$start->format('Y-m-d')] = $start;
        }

        if (count($days) < self::MINIMUM_DAYS) {
            return null;
        }

        ksort($days);

        // The year joins the name once the days span more than one, because the same day of two years would otherwise share a name in the table and the tooltips.
        $format = 1 === count(array_unique(array_map(static fn (DateTimeImmutable $start): string => $start->format('Y'), $starts))) ? 'M j' : 'M j, Y';

        return new self($days, array_map(static fn (DateTimeImmutable $start): string => $start->format($format), $starts));
    }

    public function dayCount(): int
    {
        return count($this->days);
    }

    public function roundsTakeaway(): string
    {
        $busiest = $this->busiestDay();

        return sprintf('Reviews ran on %d days; the busiest was %s, with %s.', $this->dayCount(), $this->name($busiest), $this->roundsReading(count($this->days[$busiest])));
    }

    public function costTakeaway(): string
    {
        $costs = array_filter($this->costs());

        if ([] === $costs) {
            return 'No day has a cost estimate yet.';
        }

        // Ranked on the unrounded dollars, because days that all round to the same reading still differ.
        $dollars = array_map(static fn (Cost $cost): float => (float) $cost->usd, $costs);
        $day = (string) array_search(max([0.0, ...$dollars]), $dollars, true);
        $reading = $costs[$day]->reading();

        return sprintf('The costliest day was %s, at %s.', $this->name($day), str_starts_with($reading, 'less') ? $reading : 'about '.$reading);
    }

    public function roundsTop(): int
    {
        return ChartScale::topFor(max([0, ...array_map(count(...), $this->days)]));
    }

    public function costTop(): int
    {
        return ChartScale::topFor(max([0, ...array_map($this->units(...), array_filter($this->costs()))]));
    }

    public function costDivisor(): int
    {
        return self::COST_UNIT;
    }

    /**
     * @return list<ChartBar>
     */
    public function roundBars(): array
    {
        return array_map(
            fn (string $day, array $rounds): ChartBar => new ChartBar($this->name($day), count($rounds), $this->roundsReading(count($rounds)), $this->name($day)),
            array_keys($this->days),
            array_values($this->days),
        );
    }

    /**
     * @return list<ChartBar>
     */
    public function costBars(): array
    {
        $bars = [];

        foreach ($this->costs() as $day => $cost) {
            $bars[] = null === $cost
                ? new ChartBar($this->name($day), null, 'No data', $this->name($day).', no cost estimate', null, 'No data')
                : new ChartBar($this->name($day), $this->units($cost), $cost->reading().', estimated', $this->name($day), null, str_replace(' USD', '', $cost->reading()));
        }

        return $bars;
    }

    // A day's cost is known only when every round that made a call has an estimate, because a partial sum would read as the whole.
    /**
     * @return array<string, Cost|null>
     */
    private function costs(): array
    {
        return array_map(static function (array $rounds): ?Cost {
            $spent = array_values(array_filter($rounds, static fn (ReviewedRound $round): bool => $round->tokens->total() > 0));

            if ([] === $spent) {
                return null;
            }

            $total = array_reduce($spent, static fn (Cost $sum, ReviewedRound $round): Cost => $sum->plus($round->cost), Cost::none());

            return null === $total->usd ? null : $total;
        }, $this->days);
    }

    // A positive cost below one unit still draws the smallest bar, because a priced day must never look free.
    private function units(Cost $cost): int
    {
        $usd = (float) $cost->usd;

        return $usd > 0.0 ? max(1, (int) round($usd * self::COST_UNIT)) : 0;
    }

    private function busiestDay(): string
    {
        $counts = array_map(count(...), $this->days);

        return (string) array_search(max([0, ...$counts]), $counts, true);
    }

    private function name(string $day): string
    {
        return $this->names[$day] ?? $day;
    }

    private function roundsReading(int $count): string
    {
        return sprintf('%d %s', $count, 1 === $count ? 'round' : 'rounds');
    }
}
