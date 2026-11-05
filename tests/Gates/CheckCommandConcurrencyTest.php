<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ChildReport;
use Intakt\Gates\GateSlots;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[CoversClass(ChildReport::class)]
#[CoversClass(GateSlots::class)]
#[UsesClass(ProcessRunner::class)]
final class CheckCommandConcurrencyTest extends SandboxTestCase
{
    private const string FINDS_THE_GATE = 'for argument in "$@"; do case "$argument" in --gate=*) gate="${argument#--gate=}";; esac; done'."\n";

    public function testASlowPassingGateAndAFastFailingGateAreReportedInTheGateOrder(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            echo "start $gate" >> log
            if [ "$gate" = phpstan ]; then
                echo '{"gate":"phpstan","ok":false,"findings":["slow down"],"omitted":0}'
                echo "end $gate" >> log
                touch phpstan.done
                exit 1
            fi
            waited=0
            while [ ! -e phpstan.done ] && [ "$waited" -lt 300 ]; do sleep 0.1; waited=$((waited + 1)); done
            echo "end $gate" >> log
            echo '{"gate":"cs","ok":true,"findings":[],"omitted":0}'
            SH);

        $run = $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => '2'], '--only=cs,phpstan');

        self::assertSame(['end phpstan', 'end cs'], $this->endsIn($sandbox), $run->output());
        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['cs', 'phpstan'], $this->reportedGates($run));
        self::assertTrue($run->passed('cs'));
        self::assertSame('slow down', $run->findingsOf('phpstan'));
        self::assertStringContainsString('"result":"fail","failed":["phpstan"]', $run->output());
    }

    public function testAGateWhoseChildCrashesFailsWithTheChildOutput(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            if [ "$gate" = cs ]; then echo 'PHP Fatal error: the gate exploded'; exit 255; fi
            echo '{"gate":"phpstan","ok":true,"findings":[],"omitted":0}'
            SH);

        $run = $this->checkWithFakeChildren($sandbox, [], '--only=cs,phpstan');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('cs'), $run->output());
        self::assertStringContainsString('exit code 255', $run->findingsOf('cs'));
        self::assertStringContainsString('PHP Fatal error: the gate exploded', $run->findingsOf('cs'));
        self::assertTrue($run->passed('phpstan'), $run->output());
        self::assertStringContainsString('"result":"fail","failed":["cs"]', $run->output());
    }

    public function testAChildLineForAnotherGateFailsTheGate(): void
    {
        $sandbox = $this->sandboxWithChild("echo '{\"gate\":\"phpstan\",\"ok\":true,\"findings\":[],\"omitted\":0}'\n");

        $run = $this->checkWithFakeChildren($sandbox, [], '--only=cs');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('cs'), $run->output());
    }

    public function testAPassingLineFromAChildThatExitedWithAFailureFailsTheGate(): void
    {
        $sandbox = $this->sandboxWithChild("echo '{\"gate\":\"cs\",\"ok\":true,\"findings\":[],\"omitted\":0}'\nexit 1\n");

        $run = $this->checkWithFakeChildren($sandbox, [], '--only=cs');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('cs'), $run->output());
        self::assertStringContainsString('exit code 1', $run->findingsOf('cs'));
    }

    public function testAPassingLineFromAChildThatExitedCleanlyPassesTheGate(): void
    {
        $sandbox = $this->sandboxWithChild("echo '{\"gate\":\"cs\",\"ok\":true,\"findings\":[],\"omitted\":0,\"note\":\"kept\"}'\n");

        $run = $this->checkWithFakeChildren($sandbox, [], '--only=cs');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame('kept', $run->noteOf('cs'));
    }

    public function testOneGateAtATimeRunsTheTestsGateFirstAndReportsInTheGateOrder(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            echo "start $gate" >> log
            echo "end $gate" >> log
            echo "{\"gate\":\"$gate\",\"ok\":true,\"findings\":[],\"omitted\":0}"
            SH);

        $run = $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => '1'], '--only=cs,tests,phpstan');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame(['start tests', 'end tests', 'start cs', 'end cs', 'start phpstan', 'end phpstan'], $this->logOf($sandbox));
        self::assertSame(['cs', 'phpstan', 'tests'], $this->reportedGates($run));
    }

    public function testGatesThatSpreadOverEveryProcessorRunOneAfterTheOther(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            echo "start $gate" >> log
            sleep 0.3
            echo "end $gate" >> log
            echo "{\"gate\":\"$gate\",\"ok\":true,\"findings\":[],\"omitted\":0}"
            SH);

        $run = $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => '4'], '--only=phpstan,rector');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame(['start phpstan', 'end phpstan', 'start rector', 'end rector'], $this->logOf($sandbox));
    }

    public function testAnotherGateRunsAlongsideAGateThatSpreadsOverEveryProcessor(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            if [ "$gate" = phpstan ]; then other=cs; else other=phpstan; fi
            touch "$gate.started"
            waited=0
            while [ ! -e "$other.started" ] && [ "$waited" -lt 100 ]; do sleep 0.1; waited=$((waited + 1)); done
            test -e "$other.started" || exit 1
            echo "{\"gate\":\"$gate\",\"ok\":true,\"findings\":[],\"omitted\":0}"
            SH);

        $run = $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => '2'], '--only=phpstan,cs');

        self::assertSame(0, $run->exitCode(), $run->output());
    }

    public function testAChildGetsTheOptionsOfTheRunAndItsGate(): void
    {
        $sandbox = $this->sandboxWithChild(<<<'SH'
            echo "$*" >> log
            echo "{\"gate\":\"$gate\",\"ok\":true,\"findings\":[],\"omitted\":0}"
            SH);

        $this->checkWithFakeChildren($sandbox, [], '--only=commits', '--fast', '--base=main', '--test-merge=a:b');

        self::assertSame(['--root='.$sandbox->root.' --only=commits --fast --base=main --test-merge=a:b --gate=commits'], $this->logOf($sandbox));
    }

    public function testAnOverrideBelowOneIsAUsageErrorBeforeAnyGateRuns(): void
    {
        $sandbox = $this->sandboxWithChild("echo started >> log\n");

        $run = $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => '0'], '--only=cs');

        self::assertSame(2, $run->exitCode(), $run->output());
        self::assertStringContainsString('INTAKT_GATES_AT_ONCE', $run->output());
        self::assertFileDoesNotExist($sandbox->root.'/log');
    }

    public function testAnOverrideThatIsNotANumberIsAUsageError(): void
    {
        $sandbox = $this->sandboxWithChild("echo started >> log\n");

        self::assertSame(2, $this->checkWithFakeChildren($sandbox, ['INTAKT_GATES_AT_ONCE' => 'two'], '--only=cs')->exitCode());
    }

    public function testAnUnknownGateForAChildIsAUsageError(): void
    {
        self::assertSame(2, $this->emptySandbox()->check('--gate=magic')->exitCode());
    }

    public function testAChildRunsItsGateAndPrintsOnlyItsLine(): void
    {
        $run = $this->sandboxWith('fully-qualified-name-in-code')->check('--gate=cs');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertCount(1, $run->lines(), $run->output());
        self::assertTrue($run->failed('cs'), $run->output());
    }

    private function sandboxWithChild(string $behavior): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('child.sh', self::FINDS_THE_GATE.$behavior);

        return $sandbox;
    }

    /**
     * @param array<string, string> $environment
     */
    private function checkWithFakeChildren(Sandbox $sandbox, array $environment, string ...$arguments): CheckRun
    {
        $home = dirname(__DIR__, 2);
        $sandbox->write('check.php', '<?php require '.var_export($home.'/vendor/autoload.php', true).';'
            .' exit(new Intakt\Gates\CheckCommand('.var_export($home, true).', ["sh", '.var_export($sandbox->root.'/child.sh', true).'])->run(array_slice($argv, 1)));');

        return new CheckRun(new ProcessRunner()->run(['php', $sandbox->root.'/check.php', '--root='.$sandbox->root, ...array_values($arguments)], $sandbox->root, $environment));
    }

    /**
     * @return list<string>
     */
    private function reportedGates(CheckRun $run): array
    {
        $gates = [];

        foreach ($run->lines() as $line) {
            if (1 === preg_match('/^\{"gate":"([a-z-]+)"/', $line, $match)) {
                $gates[] = $match[1];
            }
        }

        return $gates;
    }

    /**
     * @return list<string>
     */
    private function endsIn(Sandbox $sandbox): array
    {
        return array_values(array_filter($this->logOf($sandbox), static fn (string $line): bool => str_starts_with($line, 'end ')));
    }

    /**
     * @return list<string>
     */
    private function logOf(Sandbox $sandbox): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($sandbox->root.'/log')), static fn (string $line): bool => '' !== $line));
    }
}
