<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\PhpunitReport;
use Intakt\Gates\TestDurations;
use Intakt\Gates\TestPorts;
use Intakt\Gates\TestsGate;
use Intakt\Gates\TestWorker;
use Intakt\Gates\TestWorkers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TestsGate::class)]
#[UsesClass(TestWorker::class)]
#[UsesClass(TestPorts::class)]
#[UsesClass(TestWorkers::class)]
#[UsesClass(PhpunitReport::class)]
#[UsesClass(TestDurations::class)]
#[UsesClass(GateResult::class)]
final class TestsGateWorkersTest extends SandboxTestCase
{
    private const string FAILING_SERIAL_TEST = <<<'PHP_WRAP'
    <?php
    
    declare(strict_types=1);
    
    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;
    
    #[CoversNothing]
    #[Group('serial')]
    final class SerialTest extends TestCase
    {
        public function testFailsAlone(): void
        {
            self::fail('the serial test failed');
        }
    }
    PHP_WRAP;

    private const string PASSING_SERIAL_TEST = <<<'PHP_WRAP'
    <?php

    declare(strict_types=1);

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    #[CoversNothing]
    #[Group('serial')]
    final class SampleTest extends TestCase
    {
        public function testPassesAlone(): void
        {
            self::assertSame(2, 1 + 1);
        }
    }
    PHP_WRAP;

    public function testTwoWorkersHoldingTheSameNamesAtTheSameMomentDoNotCollide(): void
    {
        $sandbox = $this->sandboxWith('tests-worker-isolation');

        $run = $sandbox->check('--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
        self::assertFileExists($sandbox->root.'/rendezvous/done-sqlite');
        self::assertFileExists($sandbox->root.'/rendezvous/done-gates');
    }

    public function testATestInTheSerialGroupRunsOnceAfterTheWorkersInItsOwnDirectory(): void
    {
        $sandbox = $this->sandboxWith('tests-worker-isolation');

        $run = $sandbox->check('--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
        self::assertSame('ran', file_get_contents($sandbox->root.'/rendezvous/serial'));
    }

    public function testAFailingTestInTheSerialGroupFailsTheGateWithTheNameOfItsPass(): void
    {
        $sandbox = $this->sandboxWith('tests-clean');
        $sandbox->write('tests/SerialTest.php', self::FAILING_SERIAL_TEST);

        $run = $sandbox->check('--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('sqlite serial: SerialTest::testFailsAlone', $run->findingsOf('tests'));
        self::assertStringNotContainsString('sqlite: SerialTest', $run->findingsOf('tests'));
    }

    // Split from the range of this test's own worker, so the port it holds and the range it hands on are its own while other workers run.
    public function testAGateRunInsideATestWorkerTakesItsPortsFromTheRangeItInherits(): void
    {
        $range = getenv(TestWorker::PORTS_VARIABLE);
        $outerPorts = TestPorts::forWorkers(2, is_string($range) && '' !== $range ? $range : null);
        self::assertNotNull($outerPorts);
        // Held to keep the first half busy like a real neighbor; the range assertion below is what detects a nested gate that ignores its range.
        $outerServer = stream_socket_server('tcp://127.0.0.1:'.explode('-', $outerPorts->block(0))[0]);
        self::assertNotFalse($outerServer);
        $sandbox = $this->sandboxWith('tests-worker-isolation');

        try {
            $run = $sandbox->checkWith([TestWorker::PORTS_VARIABLE => $outerPorts->block(1)], '--only=tests');
        } finally {
            fclose($outerServer);
        }

        self::assertTrue($run->passed('tests'), $run->output());
        [$first, $last] = array_map(intval(...), explode('-', $outerPorts->block(1)));
        foreach (['sqlite', 'gates'] as $worker) {
            $port = (int) file_get_contents($sandbox->root.'/rendezvous/done-'.$worker);
            self::assertTrue($port >= $first && $port <= $last, sprintf('The %s worker took port %d, outside %s.', $worker, $port, $outerPorts->block(1)));
        }
    }

    public function testAnInheritedPortRangeThatIsNotARangeFailsTheGate(): void
    {
        $run = $this->sandboxWith('tests-clean')->checkWith([TestWorker::PORTS_VARIABLE => 'ports'], '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('INTAKT_TEST_PORTS names no range of ports', $run->findingsOf('tests'));
    }

    public function testAConfigurationWhoseTestsAreAllSerialPassesWhenTheyPass(): void
    {
        $sandbox = $this->sandboxWith('tests-clean');
        $sandbox->write('tests/SampleTest.php', self::PASSING_SERIAL_TEST);

        $run = $sandbox->check('--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
    }

    public function testAConfigurationWithoutAnyTestStillFails(): void
    {
        $sandbox = $this->sandboxWith('tests-clean');
        unlink($sandbox->root.'/tests/SampleTest.php');

        $run = $sandbox->check('--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
    }

    public function testTheDurationsOfTheSerialPassCountWithTheWorkers(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-worker-isolation')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertEqualsCanonicalizing(['tests/WorkerTest.php', 'tests/SerialTest.php', 'tests/Gates/GateTest.php'], $run->slowestFilesOf('tests'));
    }
}
