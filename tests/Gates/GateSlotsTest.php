<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateSlots;
use Intakt\Gates\TestsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GateSlots::class)]
#[UsesClass(TestsGate::class)]
final class GateSlotsTest extends TestCase
{
    public function testWithoutAnOverrideAsManyGatesRunAtOnceAsThereAreProcessors(): void
    {
        $slots = GateSlots::chosen(null, 3, 1);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertTrue($slots->allow(['cs', 'phpstan'], 'rector'));
        self::assertFalse($slots->allow(['cs', 'phpstan', 'rector'], 'comments'));
    }

    public function testTheOverrideSetsHowManyGatesRunAtOnce(): void
    {
        $slots = GateSlots::chosen('2', 12, 1);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertTrue($slots->allow(['cs'], 'phpstan'));
        self::assertFalse($slots->allow(['cs', 'phpstan'], 'rector'));
    }

    public function testOneSlotRunsTheGatesOneAfterAnother(): void
    {
        $slots = GateSlots::chosen('1', 12, 4);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertTrue($slots->allow([], 'tests'));
        self::assertFalse($slots->allow(['tests'], 'cs'));
        self::assertFalse($slots->allow(['cs'], 'phpstan'));
    }

    public function testAnOverrideThatNamesNoNumberOfGatesIsRefused(): void
    {
        self::assertSame([null, null, null, null], array_map(static fn (string $override): ?GateSlots => GateSlots::chosen($override, 4, 1), ['0', '-2', 'four', '1000']));
    }

    public function testTheTestWorkersTakeTheirShareOfTheSlots(): void
    {
        $slots = GateSlots::chosen(null, 8, 4);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertTrue($slots->allow(['tests', 'cs', 'phpstan', 'rector'], 'comments'));
        self::assertFalse($slots->allow(['tests', 'cs', 'phpstan', 'rector', 'comments'], 'audit'));
    }

    public function testOneOtherGateStillRunsBesideTestWorkersThatFillEveryProcessor(): void
    {
        $slots = GateSlots::chosen(null, 4, 4);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertTrue($slots->allow([], 'tests'));
        self::assertTrue($slots->allow(['tests'], 'cs'));
        self::assertFalse($slots->allow(['tests', 'cs'], 'phpstan'));
    }

    public function testAMemoryLimitAllowsOneGatePerTwoGibibytes(): void
    {
        self::assertSame([4, 1, 1], [GateSlots::byDefault(12, ["8589934592\n"], 1), GateSlots::byDefault(12, ["3758096384\n"], 1), GateSlots::byDefault(12, ['1048576'], 1)]);
    }

    public function testTheTestWorkersHoldTheirMemoryBeforeOtherGatesRunBesideThem(): void
    {
        self::assertSame([5, 2, 1], [GateSlots::byDefault(12, ['17179869184'], 4), GateSlots::byDefault(12, ['10737418240'], 4), GateSlots::byDefault(12, ['4831838208'], 4)]);
    }

    public function testTheLowestMemoryLimitOfTheCgroupAndItsParentsCounts(): void
    {
        self::assertSame(1, GateSlots::byDefault(12, ["max\n", "4831838208\n", "20401094656\n", ''], 4));
    }

    public function testAMemoryLimitAboveWhatTheProcessorsUseLeavesTheirCount(): void
    {
        self::assertSame(4, GateSlots::byDefault(4, ["17179869184\n"], 1));
    }

    public function testNoReadableMemoryLimitLeavesTheProcessorCount(): void
    {
        self::assertSame(12, GateSlots::byDefault(12, ["max\n", '', '0'], 4));
    }

    public function testTheStaticGatesStartFirstInTheirOrder(): void
    {
        $slots = GateSlots::chosen(null, 4, 1);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertSame(['cs', 'phpstan', 'secrets', 'tests'], $slots->launchOrder(['cs', 'phpstan', 'tests', 'secrets'], ['tests']));
        self::assertSame(['cs', 'phpstan'], $slots->launchOrder(['cs', 'phpstan'], []));
    }

    public function testTheTestsGateStartsFirstOfTheGatesThatRunTheChangesCode(): void
    {
        $slots = GateSlots::chosen(null, 4, 1);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertSame(['cs', 'secrets', 'tests', 'container'], $slots->launchOrder(['cs', 'container', 'tests', 'secrets'], ['container', 'tests']));
    }
}
