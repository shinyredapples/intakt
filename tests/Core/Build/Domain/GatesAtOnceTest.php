<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\GatesAtOnce;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GatesAtOnce::class)]
final class GatesAtOnceTest extends TestCase
{
    public function testTwelveProcessorsAndOneBuildSlotRunEightGatesAtOnce(): void
    {
        self::assertSame(8, new GatesAtOnce(12, 1)->count);
    }

    public function testTwelveProcessorsAndTwoBuildSlotsRunSixGatesAtOnce(): void
    {
        self::assertSame(6, new GatesAtOnce(12, 2)->count);
    }

    public function testTwelveProcessorsAndThreeBuildSlotsRunFourGatesAtOnce(): void
    {
        self::assertSame(4, new GatesAtOnce(12, 3)->count);
    }

    public function testTwoProcessorsAndThreeBuildSlotsStillRunTheFloorOfTwoGates(): void
    {
        self::assertSame(2, new GatesAtOnce(2, 3)->count);
    }

    public function testSixteenProcessorsAndTwoBuildSlotsRunEightGatesAtOnce(): void
    {
        self::assertSame(8, new GatesAtOnce(16, 2)->count);
    }

    public function testEighteenProcessorsAndTwoBuildSlotsAreCappedAtEightGates(): void
    {
        self::assertSame(8, new GatesAtOnce(18, 2)->count);
    }

    public function testACountBelowTheMostAJobAllowsOverridesTheChecksOwnDefault(): void
    {
        self::assertSame(4, new GatesAtOnce(12, 3)->overridingBelow(8));
    }

    public function testACountAtTheMostAJobAllowsLeavesTheChecksOwnDefault(): void
    {
        self::assertNull(new GatesAtOnce(12, 6)->overridingBelow(2));
    }

    public function testACountAboveTheMostAJobAllowsLeavesTheChecksOwnDefault(): void
    {
        self::assertNull(new GatesAtOnce(12, 1)->overridingBelow(2));
    }

    public function testNoBuildSlotIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatesAtOnce(12, 0);
    }

    public function testNoProcessorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatesAtOnce(0, 1);
    }
}
