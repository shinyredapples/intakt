<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestWorker;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestWorker::class)]
final class TestWorkerTest extends SandboxTestCase
{
    private const int FIRST_EPHEMERAL_PORT = 32768;

    public function testTwoWorkersShareNoPath(): void
    {
        $first = TestWorker::numbered('/scratch', 'sqlite', 0)->environment();
        $second = TestWorker::numbered('/scratch', 'gates', 1)->environment();

        self::assertSame([], array_intersect(array_values($first), array_values($second)));
    }

    public function testTheSerialWorkerSharesNoPathWithTheWorkerOfItsPass(): void
    {
        $worker = TestWorker::numbered('/scratch', 'sqlite', 0)->environment();
        $serial = TestWorker::serial('/scratch', 'sqlite')->environment();

        self::assertSame([], array_intersect(array_diff_key($worker, ['INTAKT_TEST_PORTS' => true]), $serial));
    }

    public function testTwoWorkersGetPortRangesThatDoNotOverlap(): void
    {
        [$firstFrom, $firstTo] = $this->portsOf(TestWorker::numbered('/scratch', 'sqlite', 0));
        [$secondFrom, $secondTo] = $this->portsOf(TestWorker::numbered('/scratch', 'gates', 1));

        self::assertLessThan($secondFrom, $firstTo);
        self::assertLessThanOrEqual($firstTo, $firstFrom);
        self::assertLessThanOrEqual($secondTo, $secondFrom);
    }

    public function testTheSerialWorkerRunsAloneSoItTakesThePortsOfTheFirstWorker(): void
    {
        self::assertSame($this->portsOf(TestWorker::numbered('/scratch', 'sqlite', 0)), $this->portsOf(TestWorker::serial('/scratch', 'sqlite')));
    }

    public function testThePortsOfTheFourthWorkerStayBelowThePortsTheSystemPicksForPortZero(): void
    {
        [, $last] = $this->portsOf(TestWorker::numbered('/scratch', 'sqlite', 3));

        self::assertLessThan(self::FIRST_EPHEMERAL_PORT, $last);
    }

    public function testThePortRangeOfAWorkerHoldsOneHundredPorts(): void
    {
        [$from, $to] = $this->portsOf(TestWorker::numbered('/scratch', 'sqlite', 2));

        self::assertSame(100, $to - $from + 1);
    }

    public function testAPreparedWorkerHasItsTemporaryDirectory(): void
    {
        $scratch = $this->emptySandbox()->root;
        $worker = TestWorker::numbered($scratch, 'sqlite', 0);

        self::assertTrue($worker->prepared());
        self::assertDirectoryExists($worker->environment()['TMPDIR']);
    }

    public function testAWorkerThatCannotCreateItsDirectoryIsNotPrepared(): void
    {
        $scratch = $this->emptySandbox()->root;
        file_put_contents($scratch.'/sqlite', 'a file where the pass directory belongs');

        self::assertFalse(@TestWorker::numbered($scratch, 'sqlite', 0)->prepared());
    }

    public function testTheJunitReportOfAWorkerLiesInItsOwnDirectory(): void
    {
        self::assertSame('/scratch/sqlite/worker-0/junit.xml', TestWorker::numbered('/scratch', 'sqlite', 0)->junitReport());
        self::assertSame('/scratch/sqlite/serial/junit.xml', TestWorker::serial('/scratch', 'sqlite')->junitReport());
    }

    public function testFindingsOfTheSerialWorkerNameItsPass(): void
    {
        self::assertSame('sqlite', TestWorker::numbered('/scratch', 'sqlite', 0)->run);
        self::assertSame('sqlite serial', TestWorker::serial('/scratch', 'sqlite')->run);
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
