<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LowPriority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LowPriority::class)]
final class LowPriorityTest extends TestCase
{
    private string $autogroup;

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

    public function testAGateBesideTheTestsGateRunsAtLowPriority(): void
    {
        self::assertTrue(LowPriority::isFor('phpstan'));
    }

    public function testTheTestsGateKeepsItsPriority(): void
    {
        self::assertFalse(LowPriority::isFor('tests'));
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
}
