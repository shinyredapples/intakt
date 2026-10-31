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
        $this->assertAcceptedBy('tests', 'tests-clean');
    }

    public function testAMockWithoutExpectationsFailsTheGate(): void
    {
        $this->assertRejectedBy('tests', 'tests-mock-without-expectations', 'No expectations were configured for the mock object');
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
