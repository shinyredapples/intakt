<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

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
        $names = [];

        foreach ($rounds as $round) {
            $start = $round->startedAt->setTimezone(new DateTimeZone('UTC'));
            $days[$start->format('Y-m-d')][] = $round;
            $names[$start->format('Y-m-d')] = $start->format('M j');
        }

        if (count($days) < self::MINIMUM_DAYS) {
            return null;
        }

        ksort($days);

        return new self($days, $names);
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
        $costs = array_filter($this->costs(), static fn (?int $cost): bool => null !== $cost);

        if ([] === $costs) {
            return 'No day has a cost estimate yet.';
        }

        $day = (string) array_search(max([0, ...$costs]), $costs, true);

        return sprintf('The costliest day was %s, at about %s USD.', $this->name($day), $this->dollars($costs[$day] ?? 0));
    }

    public function roundsTop(): int
    {
        return ChartScale::topFor(max([0, ...array_map(count(...), $this->days)]));
    }

    public function costTop(): int
    {
        return ChartScale::topFor(max([0, ...array_filter($this->costs(), static fn (?int $cost): bool => null !== $cost)]));
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
                : new ChartBar($this->name($day), $cost, $this->dollars($cost).' USD, estimated', $this->name($day), null, $this->dollars($cost));
        }

        return $bars;
    }

    // A day's cost is known only when every round that made a call has an estimate, because a partial sum would read as the whole.
    /**
     * @return array<string, int|null>
     */
    private function costs(): array
    {
        return array_map(static function (array $rounds): ?int {
            $spent = array_values(array_filter($rounds, static fn (ReviewedRound $round): bool => $round->tokens->total() > 0));

            if ([] === $spent) {
                return null;
            }

            $total = array_reduce($spent, static fn (Cost $sum, ReviewedRound $round): Cost => $sum->plus($round->cost), Cost::none());

            return null === $total->usd ? null : (int) round($total->usd * self::COST_UNIT);
        }, $this->days);
    }

    private function busiestDay(): string
    {
        $counts = array_map(count(...), $this->days);

        return (string) array_search(max([0, ...$counts]), $counts, true);
    }

    private function roundsReading(int $count): string
    {
        return sprintf('%d %s', $count, 1 === $count ? 'round' : 'rounds');
    }

    private function dollars(int $units): string
    {
        return sprintf('%.4f', $units / self::COST_UNIT);
    }

    private function name(string $day): string
    {
        return $this->names[$day] ?? $day;
    }
}
