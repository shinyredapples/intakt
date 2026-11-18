<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Gates\GateSlots;
use Intakt\Gates\Processors;
use Intakt\Gates\StaticAnalysisGate;
use Intakt\Gates\TestWorkerCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GatesByDefault::class)]
final class GatesByDefaultTest extends TestCase
{
    private const int GIB = 1024 ** 3;

    public function testAJobOfTwoCpusAndFourGibRunsOneGateAtOnceBecauseItsTwoTestWorkersHoldBothMemoryShares(): void
    {
        self::assertSame(1, new GatesByDefault(12, 2.0, 4 * self::GIB)->count);
    }

    public function testAJobOfEightCpusAndThirtyTwoGibRunsEightGatesAtOnce(): void
    {
        self::assertSame(8, new GatesByDefault(12, 8.0, 32 * self::GIB)->count);
    }

    public function testAJobOfEightCpusAndTwelveGibRunsTheThreeGatesItsFourTestWorkersLeaveMemoryFor(): void
    {
        self::assertSame(3, new GatesByDefault(12, 8.0, 12 * self::GIB)->count);
    }

    public function testAJobWithMoreCpusThanTheHostHasProcessorsRunsNoMoreGatesThanProcessors(): void
    {
        self::assertSame(2, new GatesByDefault(2, 8.0, 32 * self::GIB)->count);
    }

    public function testAJobOfAFractionOfACpuCountsItRoundedUp(): void
    {
        self::assertSame(3, new GatesByDefault(12, 2.5, 32 * self::GIB)->count);
    }

    public function testAJobWithLessMemoryThanOneShareStillRunsOneGate(): void
    {
        self::assertSame(1, new GatesByDefault(12, 8.0, self::GIB)->count);
    }

    public function testTheMemoryShareIsTheOneTheCheckGivesEachGate(): void
    {
        self::assertSame(StaticAnalysisGate::MEMORY_LIMIT_GIB * self::GIB, GatesByDefault::GATE_MEMORY_BYTES);
    }

    public function testTheCountIsTheOneTheCheckPicksFromTheSameLimits(): void
    {
        foreach ([1, 2, 3, 4, 6, 12, 16, 64] as $processors) {
            foreach ([0.5, 1.0, 1.5, 2.0, 3.0, 4.0, 5.0, 8.0, 16.0] as $cpus) {
                foreach ([1, 2, 3, 4, 7, 8, 9, 12, 16, 32, 64] as $gib) {
                    self::assertSame(
                        $this->theChecksDefault($processors, $cpus, $gib * self::GIB),
                        new GatesByDefault($processors, $cpus, $gib * self::GIB)->count,
                        sprintf('%d processors, %.1f CPUs, %d GiB', $processors, $cpus, $gib),
                    );
                }
            }
        }
    }

    // What `bin/check` reads inside such a job: every processor of the host in its affinity list, the CPU limit as its quota and the memory limit as `memory.max`.
    private function theChecksDefault(int $processors, float $cpus, int $memoryBytes): int
    {
        $status = sprintf("Cpus_allowed_list:\t0-%d\n", $processors - 1);
        $cpuMax = sprintf('%d 100000', (int) round($cpus * 100000));
        $testWorkers = TestWorkerCount::chosen(null, $status, $cpuMax);
        self::assertNotNull($testWorkers);

        return GateSlots::byDefault(Processors::available($status, $cpuMax), [(string) $memoryBytes], $testWorkers);
    }
}
