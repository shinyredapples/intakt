<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ChildCommand;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateSlots;
use Intakt\Gates\LowPriority;
use Intakt\Gates\OwnCgroup;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\Processors;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RunningChild;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Process\Process;

#[CoversClass(GateChildren::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateSlots::class)]
#[UsesClass(LowPriority::class)]
#[UsesClass(OwnCgroup::class)]
#[UsesClass(Processors::class)]
#[UsesClass(RunningChild::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(StopSignalForwarding::class)]
final class GateChildrenTest extends SandboxTestCase
{
    private const int SAFETY_LIMIT_NANOSECONDS = 90_000_000_000;

    public function testASlowPassingGateAndAFastFailingGateAreReportedInGateOrder(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '1'], '--only=secrets,cs');

        self::assertSame([
            '{"gate":"cs","ok":true,"findings":[],"omitted":0}',
            '{"gate":"secrets","ok":false,"findings":["src/Report.php:3 broken"],"omitted":0}',
            '{"result":"fail","failed":["secrets"],"report":"var/check/report-<name>.json"}',
        ], $this->linesNamingAnyReport($run));
        self::assertSame(1, $run->exitCode());
    }

    public function testASlowPassingGateAndAFastFailingGateRunningAtOnceAreReportedInGateOrder(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '2'], '--only=secrets,cs');

        self::assertSame([
            '{"gate":"cs","ok":true,"findings":[],"omitted":0}',
            '{"gate":"secrets","ok":false,"findings":["src/Report.php:3 broken"],"omitted":0}',
            '{"result":"fail","failed":["secrets"],"report":"var/check/report-<name>.json"}',
        ], $this->linesNamingAnyReport($run));
        self::assertSame(1, $run->exitCode());
    }

    public function testTwoSlotsRunALaterGateWhileAnEarlierOneIsStillRunning(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '2'], '--only=architecture,comments');

        self::assertFalse($run->failed('architecture'), $run->output());
        self::assertSame(0, $run->exitCode());
    }

    public function testOneSlotRunsTheGatesOneAfterAnother(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '1'], '--only=highlights,reproductions');

        self::assertFalse($run->failed('reproductions'), $run->output());
        self::assertSame(0, $run->exitCode());
    }

    public function testAGateBesideTheTestsGateIsAskedToRunAtLowPriority(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '2'], '--only=tests,container');

        self::assertSame('container was asked to run at low priority', $run->findingsOf('container'));
        self::assertFalse($run->failed('tests'), $run->output());
    }

    public function testOneSlotAsksNoGateToRunAtLowPriority(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '1'], '--only=tests,container');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    public function testWithoutTheTestsGateNoGateIsAskedToRunAtLowPriority(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '2'], '--only=container,audit');

        self::assertFalse($run->failed('container'), $run->output());
    }

    // Because a nested run, such as the gate tests start, inherits the environment of a child that may have been asked.
    public function testAnAskBinCheckInheritedReachesNoChild(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '1', LowPriority::VARIABLE => '1'], '--only=tests,container');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    public function testAStaticGateSelectedWithTheTestsGateIsNotAskedToRunAtLowPriority(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '4'], '--only=comments,tests');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    // Because a test of the change could otherwise alter the tools or inputs of a static gate while that gate judges the head.
    public function testAGateThatRunsTheChangesCodeStartsOnlyAfterTheLastStaticGateHasEnded(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '4'], '--only=tests,audit,comments');

        self::assertSame([
            '{"gate":"comments","ok":true,"findings":[],"omitted":0}',
            '{"gate":"audit","ok":true,"findings":[],"omitted":0}',
            '{"gate":"tests","ok":true,"findings":[],"omitted":0}',
            '{"result":"pass","failed":[],"report":"var/check/report-<name>.json"}',
        ], $this->linesNamingAnyReport($run));
    }

    public function testTheContainerGateAlsoWaitsForTheStaticGates(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '4'], '--only=container,audit');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    // Because the phpstan gate boots the change's kernel to dump the test container, which runs the change's code.
    public function testThePhpstanGateStartsOnlyAfterTheLastStaticGateHasEnded(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => '4'], '--only=phpstan,audit,comments');

        self::assertSame([
            '{"gate":"phpstan","ok":true,"findings":[],"omitted":0}',
            '{"gate":"comments","ok":true,"findings":[],"omitted":0}',
            '{"gate":"audit","ok":true,"findings":[],"omitted":0}',
            '{"result":"pass","failed":[],"report":"var/check/report-<name>.json"}',
        ], $this->linesNamingAnyReport($run));
    }

    public function testAnOverrideThatNamesNoNumberOfGatesStopsBeforeAnyGateStarts(): void
    {
        $run = $this->checkWithFakeGatesIn([GateSlots::VARIABLE => 'all'], '--only=comments');

        self::assertSame(['{"error":"INTAKT_GATE_SLOTS names no number of gates to run at once from 1 to 999"}'], $run->lines());
        self::assertSame(2, $run->exitCode());
    }

    public function testAnInterruptedCheckStopsEveryChildRunningAtThatMoment(): void
    {
        $home = $this->fakeGatesHome();
        $check = new Process([PHP_BINARY, $home->root.'/bin/check', '--root='.$this->emptySandbox()->root, '--only=commits,dashes'], $home->root, [GateSlots::VARIABLE => '2']);
        $check->start();
        $pids = $this->pidsOnceWritten($home->root, ['commits', 'dashes']);

        $check->signal(15);
        $check->wait();

        self::assertNotContains(0, $pids, 'both children wrote their process id');
        self::assertSame([false, false], array_map($this->runsOnAfterAWhile(...), $pids));
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

    /**
     * @param array<string, string> $environment
     */
    private function checkWithFakeGatesIn(array $environment, string ...$arguments): CheckRun
    {
        return new CheckRun($this->runWithFakeGatesIn($environment, ...$arguments));
    }

    // Because each report gets a fresh random name, the lines compare equal only with that name replaced.
    /**
     * @return list<string>
     */
    private function linesNamingAnyReport(CheckRun $run): array
    {
        return array_map(static fn (string $line): string => (string) preg_replace('~report-[0-9a-f]{16}\.json~', 'report-<name>.json', $line), $run->lines());
    }

    private function runWithFakeGates(string ...$arguments): ProcessResult
    {
        return $this->runWithFakeGatesIn([GateSlots::VARIABLE => '1'], ...$arguments);
    }

    // Because the children run the home's own child entry, a home whose entry fakes the gates fixes their timing and outcome.
    /**
     * @param array<string, string> $environment
     */
    private function runWithFakeGatesIn(array $environment, string ...$arguments): ProcessResult
    {
        $home = $this->fakeGatesHome();
        $repository = $this->emptySandbox();

        return new ProcessRunner()->run(['php', $home->root.'/bin/check', '--root='.$repository->root, ...array_values($arguments)], $home->root, $environment);
    }

    private function fakeGatesHome(): Sandbox
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('gate-children');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');

        return $home;
    }

    /**
     * @param list<string> $gates
     *
     * @return list<int>
     */
    private function pidsOnceWritten(string $directory, array $gates): array
    {
        $files = array_map(static fn (string $gate): string => $directory.'/'.$gate.'.pid', $gates);
        $until = hrtime(true) + self::SAFETY_LIMIT_NANOSECONDS;

        while ([] !== array_filter($files, static fn (string $file): bool => '' === (string) @file_get_contents($file)) && hrtime(true) < $until) {
            usleep(10000);
        }

        return array_map(static fn (string $file): int => (int) @file_get_contents($file), $files);
    }

    // Because a stopped child whose parent died stays a zombie until it is reaped, which takes a moment on a busy machine; the limit only ends a hang.
    private function runsOnAfterAWhile(int $pid): bool
    {
        $until = hrtime(true) + self::SAFETY_LIMIT_NANOSECONDS;

        while ($this->runs($pid) && hrtime(true) < $until) {
            usleep(10000);
        }

        return $this->runs($pid);
    }

    private function runs(int $pid): bool
    {
        $status = (string) @file_get_contents('/proc/'.$pid.'/stat');
        $end = strrpos($status, ')');

        return false !== $end && 'Z' !== substr($status, $end + 2, 1);
    }
}
