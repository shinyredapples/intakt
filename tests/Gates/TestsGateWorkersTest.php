<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\PhpunitReport;
use Intakt\Gates\TestDurations;
use Intakt\Gates\TestsGate;
use Intakt\Gates\TestWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TestsGate::class)]
#[UsesClass(TestWorker::class)]
#[UsesClass(PhpunitReport::class)]
#[UsesClass(TestDurations::class)]
#[UsesClass(GateResult::class)]
final class TestsGateWorkersTest extends SandboxTestCase
{
    private const string FAILING_SERIAL_TEST = <<<'PHP'
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
        PHP;

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

    public function testTheDurationsOfTheSerialPassCountWithTheWorkers(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-worker-isolation')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertEqualsCanonicalizing(['tests/WorkerTest.php', 'tests/SerialTest.php', 'tests/Gates/GateTest.php'], $run->slowestFilesOf('tests'));
    }
}
