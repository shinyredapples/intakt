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
        self::assertSame([4, 1, 1], [GateSlots::byDefault(12, "8589934592\n"), GateSlots::byDefault(12, "3758096384\n"), GateSlots::byDefault(12, '1048576')]);
    }

    public function testAMemoryLimitAboveWhatTheProcessorsUseLeavesTheirCount(): void
    {
        self::assertSame(4, GateSlots::byDefault(4, "17179869184\n"));
    }

    public function testNoReadableMemoryLimitLeavesTheProcessorCount(): void
    {
        self::assertSame([12, 12, 12], array_map(static fn (string $memoryMax): int => GateSlots::byDefault(12, $memoryMax), ["max\n", '', '0']));
    }

    public function testTheTestsGateStartsFirstAndTheOthersKeepTheirOrder(): void
    {
        $slots = GateSlots::chosen(null, 4, 1);

        self::assertInstanceOf(GateSlots::class, $slots);
        self::assertSame(['tests', 'cs', 'phpstan', 'secrets'], $slots->launchOrder(['cs', 'phpstan', 'tests', 'secrets']));
        self::assertSame(['cs', 'phpstan'], $slots->launchOrder(['cs', 'phpstan']));
    }
}
