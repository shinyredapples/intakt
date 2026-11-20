<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewsPerDay;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewsPerDay::class)]
#[CoversClass(ChartBar::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ChartScale::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class ReviewsPerDayTest extends TestCase
{
    public function testReviewsOnOnlyTwoDaysGiveNoPerDayChart(): void
    {
        self::assertNull(ReviewsPerDay::of([$this->round('r-1', '2026-10-26T10:00:00+00:00'), $this->round('r-2', '2026-10-26T11:00:00+00:00'), $this->round('r-3', '2026-10-27T10:00:00+00:00')]));
    }

    public function testNoRoundGivesNoPerDayChart(): void
    {
        self::assertNull(ReviewsPerDay::of([]));
    }

    public function testReviewsOnThreeDaysCountTheRoundsOfEachDayOldestFirst(): void
    {
        $perDay = $this->drawn([$this->round('r-1', '2026-10-28T10:00:00+00:00'), $this->round('r-2', '2026-10-26T10:00:00+00:00'), $this->round('r-3', '2026-10-27T10:00:00+00:00'), $this->round('r-4', '2026-10-28T12:00:00+00:00')]);

        self::assertSame(['1 round, Oct 26', '1 round, Oct 27', '2 rounds, Oct 28'], array_map(static fn (ChartBar $bar): string => $bar->inWords(), $perDay->roundBars()));
        self::assertSame(3, $perDay->dayCount());
        self::assertSame(2, $perDay->roundsTop());
    }

    public function testRoundsAreGroupedByTheirUtcStartDay(): void
    {
        $perDay = $this->drawn([$this->round('r-1', '2026-10-26T23:30:00-02:00'), $this->round('r-2', '2026-10-27T10:00:00+00:00'), $this->round('r-3', '2026-10-28T01:00:00+03:00'), $this->round('r-4', '2026-10-28T23:00:00+00:00'), $this->round('r-5', '2026-10-29T00:30:00+00:00')]);

        self::assertSame(['3 rounds, Oct 27', '1 round, Oct 28', '1 round, Oct 29'], array_map(static fn (ChartBar $bar): string => $bar->inWords(), $perDay->roundBars()));
    }

    public function testADayWithoutAPricedRoundReadsNoDataForCostNeverZero(): void
    {
        $perDay = $this->drawn([$this->round('r-1', '2026-10-26T10:00:00+00:00'), $this->priced('r-2', '2026-10-27T10:00:00+00:00', 0.0123), $this->round('r-3', '2026-10-28T10:00:00+00:00')]);

        $bars = $perDay->costBars();
        self::assertSame([null, 123, null], array_map(static fn (ChartBar $bar): ?int => $bar->value, $bars));
        self::assertSame(['No data', '0.0123', 'No data'], array_map(static fn (ChartBar $bar): string => $bar->directLabel(), $bars));
        self::assertSame('No data', $bars[0]->reading);
    }

    public function testCostPerDaySumsTheEstimatesOfThatDaysRounds(): void
    {
        $perDay = $this->drawn([$this->priced('r-1', '2026-10-26T10:00:00+00:00', 0.01), $this->priced('r-2', '2026-10-26T11:00:00+00:00', 0.0025), $this->priced('r-3', '2026-10-27T10:00:00+00:00', 0.001), $this->priced('r-4', '2026-10-28T10:00:00+00:00', 0.002)]);

        self::assertSame(['0.0125 USD, estimated, Oct 26', '0.0010 USD, estimated, Oct 27', '0.0020 USD, estimated, Oct 28'], array_map(static fn (ChartBar $bar): string => $bar->inWords(), $perDay->costBars()));
        self::assertSame(200, $perDay->costTop());
    }

    public function testADayWithAnUnpricedRoundReadsNoDataBecauseAPartialSumWouldReadAsTheWhole(): void
    {
        $unpriced = $this->round('r-2', '2026-10-26T11:00:00+00:00')->withTokens(new TokenFigures(100, 0, 0, 0, 20))->withCost(Cost::unknown('model-x'));
        $perDay = $this->drawn([$this->priced('r-1', '2026-10-26T10:00:00+00:00', 0.01), $unpriced, $this->round('r-3', '2026-10-27T10:00:00+00:00'), $this->round('r-4', '2026-10-28T10:00:00+00:00')]);

        self::assertNull($perDay->costBars()[0]->value);
    }

    public function testTheTakeawaysNameTheBusiestAndTheCostliestDay(): void
    {
        $perDay = $this->drawn([$this->priced('r-1', '2026-10-26T10:00:00+00:00', 0.01), $this->round('r-2', '2026-10-27T10:00:00+00:00'), $this->round('r-3', '2026-10-27T11:00:00+00:00'), $this->round('r-4', '2026-10-28T10:00:00+00:00')]);

        self::assertSame('Reviews ran on 3 days; the busiest was Oct 27, with 2 rounds.', $perDay->roundsTakeaway());
        self::assertSame('The costliest day was Oct 26, at about 0.0100 USD.', $perDay->costTakeaway());
    }

    public function testWithoutAnyEstimateTheCostTakeawaySaysSo(): void
    {
        $perDay = $this->drawn([$this->round('r-1', '2026-10-26T10:00:00+00:00'), $this->round('r-2', '2026-10-27T10:00:00+00:00'), $this->round('r-3', '2026-10-28T10:00:00+00:00')]);

        self::assertSame('No day has a cost estimate yet.', $perDay->costTakeaway());
        self::assertSame(2, $perDay->costTop());
    }

    public function testATinyPositiveCostIsNeverShownAsZeroAndStillDrawsABar(): void
    {
        $perDay = $this->drawn([$this->priced('r-1', '2026-10-01T10:00:00+00:00', 0.00001), $this->priced('r-2', '2026-10-02T10:00:00+00:00', 0.00002), $this->priced('r-3', '2026-10-03T10:00:00+00:00', 0.00003)]);

        $bar = $perDay->costBars()[0];
        self::assertSame('less than 0.0001', $bar->directLabel());
        self::assertSame('less than 0.0001 USD, estimated, Oct 1', $bar->inWords());
        self::assertSame(1, $bar->value);
    }

    public function testTheCostliestDayIsRankedOnTheUnroundedTotals(): void
    {
        $perDay = $this->drawn([$this->priced('r-1', '2026-10-01T10:00:00+00:00', 0.00001), $this->priced('r-2', '2026-10-02T10:00:00+00:00', 0.00002), $this->priced('r-3', '2026-10-03T10:00:00+00:00', 0.00003)]);

        self::assertSame('The costliest day was Oct 3, at less than 0.0001 USD.', $perDay->costTakeaway());
    }

    public function testDaysInDifferentYearsCarryTheYearSoNoTwoDaysShareAName(): void
    {
        $perDay = $this->drawn([$this->round('r-1', '2025-10-01T10:00:00+00:00'), $this->round('r-2', '2026-10-01T10:00:00+00:00'), $this->round('r-3', '2026-10-02T10:00:00+00:00')]);

        self::assertSame(['Oct 1, 2025', 'Oct 1, 2026', 'Oct 2, 2026'], array_map(static fn (ChartBar $bar): string => $bar->label, $perDay->roundBars()));
        self::assertSame(['Oct 1, 2025', 'Oct 1, 2026', 'Oct 2, 2026'], array_map(static fn (ChartBar $bar): string => $bar->label, $perDay->costBars()));
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    private function drawn(array $rounds): ReviewsPerDay
    {
        return ReviewsPerDay::of($rounds) ?? throw new LogicException('Expected a per-day chart.');
    }

    private function round(string $round, string $at): ReviewedRound
    {
        return new ReviewedRound(new RoundStart($round, 'alpha', 2), new RoundPlace('feat/thing', 7), $this->moment($at), null);
    }

    private function moment(string $at): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(DATE_ATOM, $at, new DateTimeZone('UTC'));

        if (false === $moment) {
            throw new LogicException('Not a time: '.$at);
        }

        return $moment;
    }

    private function priced(string $round, string $at, float $usd): ReviewedRound
    {
        return $this->round($round, $at)->withTokens(new TokenFigures(100, 0, 0, 0, 20))->withCost(Cost::inUsd($usd, '2026-11-13'));
    }
}
