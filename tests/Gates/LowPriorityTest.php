<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateSlots;
use Intakt\Gates\LowPriority;
use Intakt\Gates\TestsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LowPriority::class)]
#[UsesClass(GateSlots::class)]
#[UsesClass(TestsGate::class)]
final class LowPriorityTest extends TestCase
{
    private string $autogroup;

    public function testAGateBesideTheTestsGateIsAskedToRunAtLowPriorityWhenSeveralRunAtOnce(): void
    {
        self::assertSame([LowPriority::VARIABLE => '1'], LowPriority::environmentFor('container', ['container', 'tests'], $this->slots('2')));
    }

    public function testTheTestsGateIsNotAskedToRunAtLowPriority(): void
    {
        self::assertSame([LowPriority::VARIABLE => false], LowPriority::environmentFor('tests', ['container', 'tests'], $this->slots('2')));
    }

    public function testAStaticGateThatEndsBeforeTheTestsGateStartsIsNotAskedToRunAtLowPriority(): void
    {
        self::assertSame([LowPriority::VARIABLE => false], LowPriority::environmentFor('rector', ['container', 'tests'], $this->slots('2')));
    }

    public function testWithoutTheTestsGateNoGateIsAskedToRunAtLowPriority(): void
    {
        self::assertSame([LowPriority::VARIABLE => false], LowPriority::environmentFor('container', ['container'], $this->slots('2')));
    }

    public function testWithOneSlotNoGateIsAskedToRunAtLowPriority(): void
    {
        self::assertSame([LowPriority::VARIABLE => false], LowPriority::environmentFor('container', ['container', 'tests'], $this->slots('1')));
    }

    public function testAChildThatIsAskedTakesTheLowPriority(): void
    {
        self::assertTrue(LowPriority::isAskedIn([LowPriority::VARIABLE => '1']));
    }

    public function testAChildStartedWithoutTheAskKeepsItsPriority(): void
    {
        self::assertFalse(LowPriority::isAskedIn(['PATH' => '/usr/bin']));
    }

    public function testTheSessionTakesTheLowestPriorityThroughItsAutogroup(): void
    {
        touch($this->autogroup);

        self::assertTrue(new LowPriority($this->autogroup)->takeForTheSession());
        self::assertSame((string) LowPriority::NICENESS, file_get_contents($this->autogroup));
    }

    public function testAKernelWithoutAutogroupsLeavesTheSessionAsItIs(): void
    {
        self::assertFalse(new LowPriority($this->autogroup)->takeForTheSession());
        self::assertFileDoesNotExist($this->autogroup);
    }

    protected function setUp(): void
    {
        $this->autogroup = sys_get_temp_dir().'/intakt-autogroup-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->autogroup)) {
            unlink($this->autogroup);
        }
    }

    private function slots(string $override): GateSlots
    {
        $slots = GateSlots::chosen($override, 4, 1);
        self::assertInstanceOf(GateSlots::class, $slots);

        return $slots;
    }
}
