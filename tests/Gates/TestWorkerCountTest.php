<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestPorts;
use Intakt\Gates\TestWorkerCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestWorkerCount::class)]
#[UsesClass(TestPorts::class)]
final class TestWorkerCountTest extends TestCase
{
    private const string TWO_PROCESSORS = "Name:\tphp\nCpus_allowed:\t3\nCpus_allowed_list:\t0-1\nMems_allowed_list:\t0\n";
    private const string THREE_PROCESSORS = "Cpus_allowed_list:\t0-2\n";
    private const string TWELVE_PROCESSORS = "Cpus_allowed_list:\t0-11\n";
    private const string NO_QUOTA = "max 100000\n";

    public function testTheGateRunsOneWorkerForEachProcessorItSees(): void
    {
        self::assertSame(2, TestWorkerCount::chosen(null, self::TWO_PROCESSORS, self::NO_QUOTA));
    }

    public function testProcessorsListedApartAddUp(): void
    {
        self::assertSame(3, TestWorkerCount::chosen(null, "Cpus_allowed_list:\t0,2,5\n", self::NO_QUOTA));
    }

    public function testWithoutAnOverrideTheGateRunsAtMostFourWorkers(): void
    {
        self::assertSame(4, TestWorkerCount::chosen(null, self::TWELVE_PROCESSORS, self::NO_QUOTA));
    }

    public function testAGateThatCannotTellItsProcessorsRunsOneWorker(): void
    {
        self::assertSame(1, TestWorkerCount::chosen(null, '', self::NO_QUOTA));
    }

    public function testACpuQuotaBelowTheProcessorsItSeesLimitsTheWorkersToTheQuotaRoundedUp(): void
    {
        self::assertSame(2, TestWorkerCount::chosen(null, self::TWELVE_PROCESSORS, "150000 100000\n"));
    }

    public function testACpuQuotaAboveTheProcessorsItSeesLeavesOneWorkerForEachProcessor(): void
    {
        self::assertSame(2, TestWorkerCount::chosen(null, self::TWO_PROCESSORS, "800000 100000\n"));
    }

    public function testACpuQuotaBelowOneProcessorStillRunsOneWorker(): void
    {
        self::assertSame(1, TestWorkerCount::chosen(null, self::TWELVE_PROCESSORS, "50000 100000\n"));
    }

    public function testAQuotaOfMaxAddsNoLimit(): void
    {
        self::assertSame(3, TestWorkerCount::chosen(null, self::THREE_PROCESSORS, self::NO_QUOTA));
    }

    public function testAQuotaThatDoesNotParseAddsNoLimit(): void
    {
        self::assertSame([3, 3, 3], array_map(static fn (string $cpuMax): int => (int) TestWorkerCount::chosen(null, self::THREE_PROCESSORS, $cpuMax), ['half', "0 100000\n", "100000\n"]));
    }

    public function testAQuotaThatCannotBeReadAddsNoLimit(): void
    {
        self::assertSame(3, TestWorkerCount::chosen(null, self::THREE_PROCESSORS, ''));
    }

    public function testTheOverrideMayRunMoreWorkersThanTheDefaultAllows(): void
    {
        self::assertSame(6, TestWorkerCount::chosen('6', self::TWO_PROCESSORS, self::NO_QUOTA));
    }

    public function testTheOverrideMayRunMoreWorkersThanTheQuotaAllows(): void
    {
        self::assertSame(3, TestWorkerCount::chosen('3', self::TWELVE_PROCESSORS, "100000 100000\n"));
    }

    public function testAnOverrideOfOneKeepsASingleWorker(): void
    {
        self::assertSame(1, TestWorkerCount::chosen('1', self::TWELVE_PROCESSORS, self::NO_QUOTA));
    }

    public function testAnOverrideThatIsNoPositiveNumberIsRefused(): void
    {
        self::assertSame([null, null, null, null], array_map(static fn (string $override): ?int => TestWorkerCount::chosen($override, self::TWO_PROCESSORS, self::NO_QUOTA), ['0', '-2', 'four', '1000']));
    }

    public function testTheOverrideMayNameAsManyWorkersAsTheDefaultPortsHoldBlocksFor(): void
    {
        self::assertSame(127, TestWorkerCount::chosen('127', self::TWO_PROCESSORS, self::NO_QUOTA));
    }

    public function testAnOverrideBeyondTheBlocksOfTheDefaultPortsIsRefused(): void
    {
        self::assertSame([null, null], [TestWorkerCount::chosen('128', self::TWO_PROCESSORS, self::NO_QUOTA), TestWorkerCount::chosen('500', self::TWO_PROCESSORS, self::NO_QUOTA)]);
    }

    public function testEveryCountTheOverrideAcceptsGetsPortsOfItsOwn(): void
    {
        foreach (range(1, TestWorkerCount::most()) as $override) {
            self::assertInstanceOf(TestPorts::class, TestPorts::forWorkers((int) TestWorkerCount::chosen((string) $override, '', ''), null), (string) $override);
        }
    }
}
