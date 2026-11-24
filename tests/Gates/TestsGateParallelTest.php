<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\Mode;
use Intakt\Gates\PhpunitReport;
use Intakt\Gates\ProcessQueue;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\TestDurations;
use Intakt\Gates\TestJobs;
use Intakt\Gates\TestPorts;
use Intakt\Gates\TestsGate;
use Intakt\Gates\TestWorker;
use Intakt\Gates\TestWorkerCount;
use Intakt\Gates\TestWorkers;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TestsGate::class)]
#[CoversClass(TestJobs::class)]
#[UsesClass(TestWorkerCount::class)]
#[UsesClass(ProcessQueue::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(TestWorker::class)]
#[UsesClass(TestWorkers::class)]
#[UsesClass(TestPorts::class)]
#[UsesClass(PhpunitReport::class)]
#[UsesClass(TestDurations::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(Workspace::class)]
final class TestsGateParallelTest extends SandboxTestCase
{
    private const array TWO_WORKERS = [TestWorkerCount::VARIABLE => '2'];

    public function testAFailingTestInOneWorkerFailsTheGateAndIsNamedWithItsFile(): void
    {
        $run = $this->badFilesRun();

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('sqlite tests/FailingTest.php: FailingTest::testRuns', $run->findingsOf('tests'));
        self::assertStringContainsString('the forced failure', $run->findingsOf('tests'));
    }

    public function testFindingsOfEveryWorkerAreListed(): void
    {
        $run = $this->badFilesRun();

        self::assertStringContainsString('the forced failure', $run->findingsOf('tests'));
        self::assertStringContainsString('the second failure', $run->findingsOf('tests'));
    }

    public function testASkippedTestInOneWorkerFailsTheGate(): void
    {
        self::assertStringContainsString('sqlite tests/SkippedTest.php: SkippedTest::testRuns: the forced skip', $this->badFilesRun()->findingsOf('tests'));
    }

    public function testADeprecationInOneWorkerFailsTheGate(): void
    {
        self::assertStringContainsString('sqlite tests/DeprecatingTest.php', $this->badFilesRun()->findingsOf('tests'));
    }

    public function testAWorkerThatCrashesFailsTheGateWithItsOutputAndHidesNoSiblingFinding(): void
    {
        $run = $this->badFilesRun();

        self::assertMatchesRegularExpression('~sqlite tests/CrashingTest\.php: phpunit exited with code \d+: the worker crashed~', $run->findingsOf('tests'));
        self::assertStringContainsString('the forced failure', $run->findingsOf('tests'));
        self::assertStringContainsString('SkippedTest::testRuns', $run->findingsOf('tests'));
        self::assertStringContainsString('tests/DeprecatingTest.php', $run->findingsOf('tests'));
    }

    public function testATestFileWithoutItsClassFailsTheGateWithSeveralWorkers(): void
    {
        $sandbox = $this->sandboxWithPassingFiles(2);
        $sandbox->write('tests/MissingTest.php', '<?php declare(strict_types=1);');

        $run = $sandbox->checkWith(self::TWO_WORKERS, '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('sqlite: PHPUnit Warning (Class MissingTest cannot be found in', $run->findingsOf('tests'));
    }

    public function testATestFileWithoutItsClassFailsTheGateWithOneWorker(): void
    {
        $sandbox = $this->sandboxWithPassingFiles(2);
        $sandbox->write('tests/MissingTest.php', '<?php declare(strict_types=1);');

        $run = $sandbox->checkWith([TestWorkerCount::VARIABLE => '1'], '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('Class MissingTest cannot be found in', $run->findingsOf('tests'));
    }

    public function testATestFileThatHangsIsStoppedAtTheFileTimeoutAndFailsTheGateNamingItsLastOutput(): void
    {
        $sandbox = $this->sandboxWithPassingFiles(1);
        $sandbox->write('tests/HangingTest.php', $this->testFile('HangingTest', 'fwrite(STDERR, "waiting for the server\n"); sleep(60);'));
        $workers = getenv(TestWorkerCount::VARIABLE);
        putenv(TestWorkerCount::VARIABLE.'=2');

        try {
            $result = new TestsGate(fileTimeoutSeconds: 2)->run(new Workspace($sandbox->root, dirname(__DIR__, 2), Mode::Full, null));
        } finally {
            putenv(false === $workers ? TestWorkerCount::VARIABLE : TestWorkerCount::VARIABLE.'='.$workers);
        }

        self::assertFalse($result->isPassing());
        self::assertContains('sqlite tests/HangingTest.php: phpunit exited with code 124: timed out after 2 seconds, last output: waiting for the server', $result->findings);
    }

    public function testTwoFilesRunAtTheSameTimeInWorkersOfTheirOwn(): void
    {
        $sandbox = $this->sandboxWith('tests-clean');
        $sandbox->write('tests/FirstTest.php', $this->testFile('FirstTest', $this->meeting('first', 'second')));
        $sandbox->write('tests/SecondTest.php', $this->testFile('SecondTest', $this->meeting('second', 'first')));

        $run = $sandbox->checkWith(self::TWO_WORKERS, '--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
        self::assertNotSame(file_get_contents($sandbox->root.'/first'), file_get_contents($sandbox->root.'/second'));
    }

    public function testTheWorkersTakeTheLongestRecordedFileFirst(): void
    {
        $sandbox = $this->sandboxWith('tests-clean');
        unlink($sandbox->root.'/tests/SampleTest.php');
        $sandbox->write('tests/ATest.php', $this->testFile('ATest', $this->logging('A')));
        $sandbox->write('tests/BTest.php', $this->testFile('BTest', $this->logging('B').$this->waitingFor('C')));
        $sandbox->write('tests/CTest.php', $this->testFile('CTest', $this->logging('C').' usleep(500000);'));
        $cache = $this->emptySandbox();
        $cache->write('tests/durations.json', '{"tests/CTest.php": 50.0, "tests/BTest.php": 20.0, "tests/ATest.php": 1.0, "tests/Gates/GateTest.php": 0.1}');

        $run = $sandbox->checkWith([...self::TWO_WORKERS, TestWorker::GATES_CACHE_VARIABLE => $cache->root], '--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
        self::assertSame('A', array_last(explode(' ', trim((string) file_get_contents($sandbox->root.'/order')))));
    }

    public function testASingleWorkerRunsEachPassInOneProcess(): void
    {
        $sandbox = $this->sandboxWithPassingFiles(2);
        $sandbox->write('tests/FailingTest.php', $this->testFile('FailingTest', 'self::fail("the forced failure");'));

        $run = $sandbox->checkWith([TestWorkerCount::VARIABLE => '1'], '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('sqlite: FailingTest::testRuns', $run->findingsOf('tests'));
    }

    public function testAWorkerCountBeyondThePortsFailsTheGateNamingTheRange(): void
    {
        $run = $this->sandboxWith('tests-clean')->checkWith([TestWorkerCount::VARIABLE => '500'], '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('INTAKT_TEST_WORKERS names no number of test workers from 1 to 127', $run->findingsOf('tests'));
    }

    // Because a two-worker run takes seconds, the five bad-file cases read one run over one sandbox; whichever of them runs first makes it and leaves its output in the folder the class owns (removed before and after the class), so each still runs alone under --filter.
    private function badFilesRun(): CheckRun
    {
        $record = Sandbox::ownedBy(self::class)->root.'/bad-files-run.json';
        if (is_file($record)) {
            $saved = json_decode((string) file_get_contents($record), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($saved);
            [$exitCode, $output, $errors] = [$saved['exitCode'] ?? null, $saved['output'] ?? null, $saved['errors'] ?? null];
            self::assertIsInt($exitCode);
            self::assertIsString($output);
            self::assertIsString($errors);

            return new CheckRun(new ProcessResult($exitCode, $output, $errors));
        }

        $sandbox = $this->sandboxWithPassingFiles(3);
        $sandbox->write('tests/FailingTest.php', $this->testFile('FailingTest', 'self::fail("the forced failure");'));
        $sandbox->write('tests/SecondFailingTest.php', $this->testFile('SecondFailingTest', 'self::fail("the second failure");'));
        $sandbox->write('tests/SkippedTest.php', $this->testFile('SkippedTest', 'self::markTestSkipped("the forced skip");'));
        $sandbox->write('tests/DeprecatingTest.php', $this->testFile('DeprecatingTest', 'trigger_error("the forced deprecation", E_USER_DEPRECATED); self::assertTrue(true);'));
        $sandbox->write('tests/CrashingTest.php', $this->testFile('CrashingTest', 'fwrite(STDERR, "the worker crashed\n"); exit(3);'));

        $result = $sandbox->checkWith(self::TWO_WORKERS, '--only=tests')->result();
        file_put_contents($record, json_encode(['exitCode' => $result->exitCode, 'output' => $result->output, 'errors' => $result->errors], JSON_THROW_ON_ERROR));

        return new CheckRun($result);
    }

    private function sandboxWithPassingFiles(int $count): Sandbox
    {
        $sandbox = $this->sandboxWith('tests-clean');

        foreach (range(1, $count) as $number) {
            $sandbox->write('tests/Passing'.$number.'Test.php', $this->testFile('Passing'.$number.'Test', 'self::assertSame(2, 1 + 1);'));
        }

        return $sandbox;
    }

    private function testFile(string $class, string $body): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use PHPUnit\\Framework\\Attributes\\CoversNothing;
            use PHPUnit\\Framework\\TestCase;

            #[CoversNothing]
            final class {$class} extends TestCase
            {
                public function testRuns(): void
                {
                    {$body}
                }
            }

            PHP;
    }

    // Because only two processes running at once both see each other's mark, each records its own database, then waits for the other.
    private function meeting(string $own, string $other): string
    {
        return 'file_put_contents(dirname(__DIR__)."/'.$own.'", getenv("DATABASE_URL"));'.$this->waitingFor($other, '/'.$other).' self::assertTrue(true);';
    }

    private function logging(string $name): string
    {
        return 'file_put_contents(dirname(__DIR__)."/order", "'.$name.' ", FILE_APPEND); file_put_contents(dirname(__DIR__)."/started-'.$name.'", ""); self::assertTrue(true);';
    }

    private function waitingFor(string $name, ?string $mark = null): string
    {
        $mark ??= '/started-'.$name;

        return ' $deadline = hrtime(true) + 30 * 1_000_000_000; while (!is_file(dirname(__DIR__)."'.$mark.'")) { if (hrtime(true) > $deadline) { self::fail("waited in vain for '.$name.'"); } usleep(20000); }';
    }
}
