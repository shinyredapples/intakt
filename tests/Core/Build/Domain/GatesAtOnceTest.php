<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
final class GatesAtOnceTest extends TestCase
{
    private const int GIB = 1024 ** 3;

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

    public function testElevenProcessorsAndThreeBuildSlotsRoundDownToThreeGates(): void
    {
        self::assertSame(3, new GatesAtOnce(11, 3)->count);
    }

    public function testSixteenProcessorsAndTwoBuildSlotsRunEightGatesAtOnce(): void
    {
        self::assertSame(8, new GatesAtOnce(16, 2)->count);
    }

    public function testEighteenProcessorsAndTwoBuildSlotsAreCappedAtEightGates(): void
    {
        self::assertSame(8, new GatesAtOnce(18, 2)->count);
    }

    public function testThreeBuildSlotsLowerTheEightGatesOfAJobOfEightCpusAndThirtyTwoGibToFour(): void
    {
        self::assertSame(4, new GatesAtOnce(12, 3)->loweringTheDefaultOfAJobWith(8.0, 32 * self::GIB));
    }

    public function testTwoBuildSlotsLowerTheEightGatesOfAJobOfEightCpusAndThirtyTwoGibToSix(): void
    {
        self::assertSame(6, new GatesAtOnce(12, 2)->loweringTheDefaultOfAJobWith(8.0, 32 * self::GIB));
    }

    public function testOneBuildSlotKeepsTheChecksOwnDefaultEvenWhenItsCountIsLower(): void
    {
        self::assertNull(new GatesAtOnce(12, 1)->loweringTheDefaultOfAJobWith(16.0, 64 * self::GIB));
    }

    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([6])]
    public function testSeveralBuildSlotsNeverRaiseTheOneGateOfAJobOfTwoCpusAndFourGib(int $slots): void
    {
        self::assertNull(new GatesAtOnce(12, $slots)->loweringTheDefaultOfAJobWith(2.0, 4 * self::GIB));
    }

    public function testThreeBuildSlotsOnTwoProcessorsNeverRaiseTheOneGateOfAJobOfTwoCpusAndFourGib(): void
    {
        self::assertNull(new GatesAtOnce(2, 3)->loweringTheDefaultOfAJobWith(2.0, 4 * self::GIB));
    }

    public function testACountEqualToTheJobsDefaultIsNotHandedOver(): void
    {
        self::assertNull(new GatesAtOnce(12, 3)->loweringTheDefaultOfAJobWith(4.0, 14 * self::GIB));
    }

    public function testACountOneBelowTheJobsDefaultIsHandedOver(): void
    {
        self::assertSame(4, new GatesAtOnce(12, 3)->loweringTheDefaultOfAJobWith(5.0, 16 * self::GIB));
    }

    public function testACountAboveTheGatesTheJobsMemoryHoldsIsNotHandedOver(): void
    {
        self::assertNull(new GatesAtOnce(12, 2)->loweringTheDefaultOfAJobWith(8.0, 12 * self::GIB));
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
