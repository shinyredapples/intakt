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

        self::assertSame([0.75, []], [$sum->usd, $sum->unpriced]);
    }

    public function testAKnownCostPlusAnUnknownOneIsUnknownAndNotThePartialSum(): void
    {
        $sum = Cost::inUsd(0.25)->plus(Cost::unknown('local-small'));

        self::assertSame([null, ['local-small']], [$sum->usd, $sum->unpriced]);
    }

    public function testAnUnknownCostPlusAKnownOneStaysUnknown(): void
    {
        $sum = Cost::unknown('local-small')->plus(Cost::inUsd(0.25));

        self::assertNull($sum->usd);
    }

    public function testAModelWithoutAPriceIsNamedOnceHoweverManyOfItsCallsAreAdded(): void
    {
        $sum = Cost::unknown('local-small')->plus(Cost::unknown('local-small'))->plus(Cost::unknown('gpt-5'));

        self::assertSame(['local-small', 'gpt-5'], $sum->unpriced);
    }
}
