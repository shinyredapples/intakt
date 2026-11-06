<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(GateChildren::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class GateChildrenTest extends SandboxTestCase
{
    public function testASlowPassingGateAndAFastFailingGateAreReportedInGateOrder(): void
    {
        $run = $this->checkWithFakeGates('--only=phpstan,cs');

        self::assertSame([
            '{"gate":"cs","ok":true,"findings":[],"omitted":0}',
            '{"gate":"phpstan","ok":false,"findings":["src/Report.php:3 broken"],"omitted":0}',
            '{"result":"fail","failed":["phpstan"]}',
        ], $run->lines());
        self::assertSame(1, $run->exitCode());
    }

    public function testAGateWhoseChildCrashesFailsWithTheChildsOutput(): void
    {
        $run = $this->checkWithFakeGates('--only=rector');

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('exit code 255', $run->findingsOf('rector'));
        self::assertStringContainsString('Fatal error: the rector child crashed', $run->findingsOf('rector'));
        self::assertSame(1, $run->exitCode());
    }

    public function testWhatAChildWritesToItsErrorsReachesTheErrorsOfBinCheck(): void
    {
        $run = $this->runWithFakeGates('--only=cs');

        self::assertSame('Warning: the cs child warned', trim($run->errors));
        self::assertSame(0, $run->exitCode, $run->output);
    }

    // Because a PHP without its own php.ini prints warnings on stdout, where they would spoil the child's result line.
    public function testAWarningOfTheGateLeavesTheResultLineAloneOnStdout(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('locked.txt', "locked\n");
        $sandbox->commitAll('chore: start');
        chmod($sandbox->root.'/locked.txt', 0o000);
        $entry = dirname(__DIR__, 2).'/config/gates/run-gate.php';

        try {
            $child = new ProcessRunner()->run(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', $entry, 'secrets', '--root='.$sandbox->root], $sandbox->root);
        } finally {
            chmod($sandbox->root.'/locked.txt', 0o644);
        }

        self::assertStringStartsWith('{"gate":"secrets","ok":false,"findings":["cannot copy', $child->output);
        self::assertCount(1, explode("\n", trim($child->output)), $child->output);
        self::assertStringContainsString('copy(', $child->errors);
        self::assertSame(1, $child->exitCode);
    }

    private function checkWithFakeGates(string ...$arguments): CheckRun
    {
        return new CheckRun($this->runWithFakeGates(...$arguments));
    }

    // Because the children run the home's own child entry, a home whose entry fakes the gates fixes their timing and outcome.
    private function runWithFakeGates(string ...$arguments): ProcessResult
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('gate-children');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');
        $repository = $this->emptySandbox();

        return new ProcessRunner()->run(['php', $home->root.'/bin/check', '--root='.$repository->root, ...array_values($arguments)], $home->root);
    }
}
