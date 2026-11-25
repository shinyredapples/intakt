<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestsGate::class)]
final class TestsGateTest extends SandboxTestCase
{
    private const string FAILING_GATE_TEST = <<<'FIXTURE'
        <?php

        declare(strict_types=1);

        use PHPUnit\Framework\Attributes\CoversNothing;
        use PHPUnit\Framework\TestCase;

        #[CoversNothing]
        final class GateTest extends TestCase
        {
            public function testFails(): void
            {
                self::fail('the gates pass ran');
            }
        }

        FIXTURE;

    public function testPassingTestsPassTheGate(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-clean')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('tests'), $run->output());
        $written = json_decode((string) file_get_contents($cache->root.'/tests/durations.json'), true);
        self::assertIsArray($written);
        self::assertEqualsCanonicalizing(['tests/SampleTest.php', 'tests/Gates/GateTest.php'], array_keys($written));
        self::assertEqualsCanonicalizing(['tests/SampleTest.php', 'tests/Gates/GateTest.php'], $run->slowestFilesOf('tests'));
        self::assertSame(1, substr_count($run->output(), '"slowest"'), $run->output());
    }

    public function testAMockWithoutExpectationsFailsTheGate(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-mock-without-expectations')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('No expectations were configured for the mock object', $run->findingsOf('tests'));
        self::assertFileExists($cache->root.'/tests/durations.json');
    }

    public function testAPhpunitDeprecationFailsTheGate(): void
    {
        $this->assertRejectedBy('tests', 'tests-phpunit-deprecation', '"cacheResult" attribute is deprecated');
    }

    public function testAChangeThatLeavesTheGateTestsAloneSkipsTheGatesPassAndSaysSo(): void
    {
        $sandbox = $this->branchOffABaseWithAFailingGateTest();
        $sandbox->write('src/Report.php', "<?php\n\ndeclare(strict_types=1);\n");
        $sandbox->commitAll('feat: add a report');

        $run = $sandbox->check('--only=tests', '--base=main');

        self::assertTrue($run->passed('tests'), $run->output());
        self::assertStringContainsString('gates pass skipped', $run->noteOf('tests'));
    }

    public function testAChangeToTheGateTestsRunsTheGatesPass(): void
    {
        $sandbox = $this->branchOffABaseWithAFailingGateTest();
        $sandbox->write('tests/Gates/GateTest.php', str_replace('testFails', 'testStillFails', self::FAILING_GATE_TEST));
        $sandbox->commitAll('test: touch the gate test');

        $run = $sandbox->check('--only=tests', '--base=main');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('the gates pass ran', $run->findingsOf('tests'));
        self::assertSame('', $run->noteOf('tests'));
    }

    public function testAGitFailureWhileReadingTheChangeFailsTheGateWithItsReason(): void
    {
        $sandbox = $this->branchOffABaseWithAFailingGateTest();
        $sandbox->write('src/Report.php', "<?php\n\ndeclare(strict_types=1);\n");
        $sandbox->commitAll('feat: add a report');
        chmod($sandbox->root.'/src/Report.php', 0o000);

        try {
            $run = $sandbox->check('--only=tests', '--base=main');
        } finally {
            chmod($sandbox->root.'/src/Report.php', 0o644);
        }

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('git', $run->findingsOf('tests'));
    }

    private function branchOffABaseWithAFailingGateTest(): Sandbox
    {
        $sandbox = $this->sandboxWith('tests-clean');
        $sandbox->write('tests/Gates/GateTest.php', self::FAILING_GATE_TEST);
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }
}
