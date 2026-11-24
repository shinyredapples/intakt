<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Contract;

use Intakt\Core\Budget\Contract\Cost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cost::class)]
final class CostTest extends TestCase
{
    public function testNothingSpentCostsZeroDollars(): void
    {
        self::assertSame(0.0, Cost::none()->usd);
    }

    public function testTwoKnownCostsAddUp(): void
    {
        $sum = Cost::inUsd(0.25)->plus(Cost::inUsd(0.5));

        self::assertSame([0.75, []], [$sum->usd, [...$sum->unpriced]]);
    }

    public function testAKnownCostPlusAnUnknownOneIsUnknownAndNotThePartialSum(): void
    {
        $sum = Cost::inUsd(0.25)->plus(Cost::unknown('local-small'));

        self::assertSame([null, ['local-small']], [$sum->usd, [...$sum->unpriced]]);
    }

    public function testAnUnknownCostPlusAKnownOneStaysUnknown(): void
    {
        $sum = Cost::unknown('local-small')->plus(Cost::inUsd(0.25));

        self::assertNull($sum->usd);
    }

    public function testAModelWithoutAPriceIsNamedOnceHoweverManyOfItsCallsAreAdded(): void
    {
        $sum = Cost::unknown('local-small')->plus(Cost::unknown('local-small'))->plus(Cost::unknown('gpt-5'));

        self::assertSame(['local-small', 'gpt-5'], [...$sum->unpriced]);
    }

    public function testAKnownCostReadsInFourDecimalsOfDollars(): void
    {
        self::assertSame('0.0459 USD', Cost::inUsd(0.04591)->reading());
    }

    public function testAPositiveCostBelowOneTenThousandthReadsAsLessThanThat(): void
    {
        self::assertSame('less than 0.0001 USD', Cost::inUsd(0.00001)->reading());
    }

    public function testNothingSpentReadsAsZeroNotUnknown(): void
    {
        self::assertSame('0.0000 USD', Cost::none()->reading());
    }

    public function testThePriceDateReadingNamesTheDayOfThePriceList(): void
    {
        self::assertSame('prices of 2026-11-13', Cost::inUsd(0.25, '2026-11-13')->priceDateReading());
    }

    public function testThePriceDateReadingSaysUnknownWhenNoDayIsConfigured(): void
    {
        self::assertSame('price date unknown', Cost::inUsd(0.25)->priceDateReading());
    }

    public function testAnUnknownCostNamesEachUnpricedModelOnceThroughTheGivenFilter(): void
    {
        self::assertSame('unknown, no price for <a>, <b>', Cost::unknown('a', 'b', 'a')->reading(static fn (string $model): string => '<'.$model.'>'));
    }

    public function testAnUnknownCostWithoutAModelNameReadsAsPlainUnknown(): void
    {
        self::assertSame('unknown', Cost::unknown()->reading());
    }
}
