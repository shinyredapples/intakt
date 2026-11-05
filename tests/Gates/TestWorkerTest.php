<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestPorts;
use Intakt\Gates\TestWorker;
use Intakt\Gates\TestWorkers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TestWorker::class)]
#[CoversClass(TestWorkers::class)]
#[UsesClass(TestPorts::class)]
final class TestWorkerTest extends SandboxTestCase
{
    private const int FIRST_EPHEMERAL_PORT = 32768;

    public function testTwoWorkersShareNoPath(): void
    {
        $first = $this->workers('/scratch')->numbered('sqlite', 'phpunit.dist.xml', 0)->environment();
        $second = $this->workers('/scratch')->numbered('gates', 'phpunit.gates.xml', 1)->environment();

        self::assertSame([], array_intersect(array_values($first), array_values($second)));
    }

    public function testTheSerialWorkerSharesNoPathWithTheWorkerOfItsPass(): void
    {
        $worker = $this->workers('/scratch')->numbered('sqlite', 'phpunit.dist.xml', 0)->environment();
        $serial = $this->workers('/scratch')->serial('sqlite', 'phpunit.dist.xml')->environment();

        self::assertSame([], array_intersect(array_diff_key($worker, ['INTAKT_TEST_PORTS' => true]), $serial));
    }

    public function testTwoWorkersGetPortRangesThatDoNotOverlap(): void
    {
        [$firstFrom, $firstTo] = $this->portsOf($this->workers('/scratch')->numbered('sqlite', 'phpunit.dist.xml', 0));
        [$secondFrom, $secondTo] = $this->portsOf($this->workers('/scratch')->numbered('gates', 'phpunit.gates.xml', 1));

        self::assertLessThan($secondFrom, $firstTo);
        self::assertLessThanOrEqual($firstTo, $firstFrom);
        self::assertLessThanOrEqual($secondTo, $secondFrom);
    }

    public function testTheSerialWorkerRunsAloneSoItTakesThePortsOfTheFirstWorker(): void
    {
        $workers = $this->workers('/scratch');

        self::assertSame($this->portsOf($workers->numbered('sqlite', 'phpunit.dist.xml', 0)), $this->portsOf($workers->serial('sqlite', 'phpunit.dist.xml')));
    }

    public function testThePortsOfTheFourthWorkerStayBelowThePortsTheSystemPicksForPortZero(): void
    {
        [, $last] = $this->portsOf($this->workers('/scratch')->numbered('sqlite', 'phpunit.dist.xml', 3));

        self::assertLessThan(self::FIRST_EPHEMERAL_PORT, $last);
    }

    public function testThePortRangeOfAWorkerHoldsOneHundredPorts(): void
    {
        [$from, $to] = $this->portsOf($this->workers('/scratch')->numbered('sqlite', 'phpunit.dist.xml', 2));

        self::assertSame(100, $to - $from + 1);
    }

    public function testTheWorkersLeaveOutTheSerialGroupAndTheSerialWorkerRunsOnlyIt(): void
    {
        $workers = $this->workers('/scratch');

        self::assertSame('--exclude-group=serial', $workers->numbered('sqlite', 'phpunit.dist.xml', 0)->selection);
        self::assertSame('--group=serial', $workers->serial('sqlite', 'phpunit.dist.xml')->selection);
    }

    public function testAPreparedWorkerHasItsTemporaryDirectory(): void
    {
        $worker = $this->workers($this->emptySandbox()->root)->numbered('sqlite', 'phpunit.dist.xml', 0);

        self::assertTrue($worker->prepared());
        self::assertDirectoryExists($worker->environment()['TMPDIR']);
    }

    public function testAWorkerThatCannotCreateItsDirectoryIsNotPrepared(): void
    {
        $scratch = $this->emptySandbox()->root;
        file_put_contents($scratch.'/sqlite', 'a file where the pass directory belongs');

        self::assertFalse(@$this->workers($scratch)->numbered('sqlite', 'phpunit.dist.xml', 0)->prepared());
    }

    public function testTheJunitReportOfAWorkerLiesInItsOwnDirectory(): void
    {
        $workers = $this->workers('/scratch');

        self::assertSame('/scratch/sqlite/worker-0/junit.xml', $workers->numbered('sqlite', 'phpunit.dist.xml', 0)->junitReport());
        self::assertSame('/scratch/sqlite/serial/junit.xml', $workers->serial('sqlite', 'phpunit.dist.xml')->junitReport());
    }

    public function testTheJunitReportsOfAllWorkersAreFound(): void
    {
        $workers = $this->workers($this->emptySandbox()->root);
        $reports = [$workers->numbered('sqlite', 'phpunit.dist.xml', 0)->junitReport(), $workers->serial('sqlite', 'phpunit.dist.xml')->junitReport()];
        $workers->numbered('sqlite', 'phpunit.dist.xml', 0)->prepared();
        $workers->serial('sqlite', 'phpunit.dist.xml')->prepared();
        array_map(touch(...), $reports);

        self::assertEqualsCanonicalizing($reports, $workers->junitReports());
    }

    public function testFindingsOfTheSerialWorkerNameItsPass(): void
    {
        $workers = $this->workers('/scratch');

        self::assertSame('sqlite', $workers->numbered('sqlite', 'phpunit.dist.xml', 0)->run);
        self::assertSame('sqlite serial', $workers->serial('sqlite', 'phpunit.dist.xml')->run);
    }

    private function workers(string $scratch): TestWorkers
    {
        $ports = TestPorts::forWorkers(4, null);
        self::assertNotNull($ports);

        return new TestWorkers($scratch, $ports, 4);
    }

    /**
     * @return array{int, int}
     */
    private function portsOf(TestWorker $worker): array
    {
        [$from, $to] = explode('-', $worker->environment()['INTAKT_TEST_PORTS']);

        return [(int) $from, (int) $to];
    }
}
