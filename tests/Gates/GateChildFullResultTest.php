<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ChildCommand;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\GateSlots;
use Intakt\Gates\LowPriority;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RunningChild;
use Intakt\Gates\ScratchDirectory;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Process\Process;

#[CoversClass(GateChildren::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(GateSlots::class)]
#[UsesClass(LowPriority::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(RunningChild::class)]
#[UsesClass(ScratchDirectory::class)]
#[UsesClass(StopSignalForwarding::class)]
final class GateChildFullResultTest extends SandboxTestCase
{
    private const int SAFETY_LIMIT_NANOSECONDS = 90_000_000_000;

    public function testTheParentCollectsTheWholeResultEachChildKeeps(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $slots = GateSlots::chosen('1', 1, 1);
        self::assertInstanceOf(GateSlots::class, $slots);

        $reports = new GateChildren(dirname(__DIR__, 2), $sandbox->root, $slots, [])->reportsOf(['dashes'], ['--root='.$sandbox->root, '--only=dashes']);

        self::assertCount(12, $this->findingsIn((string) $reports[0]->kept));
        self::assertStringEndsWith('"omitted":2}', $reports[0]->line);
    }

    public function testNoChildKeepsAWholeResultWhenTheScratchFolderWouldBeInsideTheCheckedTree(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $slots = GateSlots::chosen('1', 1, 1);
        self::assertInstanceOf(GateSlots::class, $slots);

        $reports = new GateChildren(dirname(__DIR__, 2), sys_get_temp_dir(), $slots, [])->reportsOf(['dashes'], ['--root='.$sandbox->root, '--only=dashes']);

        self::assertNull($reports[0]->kept);
    }

    public function testALaterGateDoesNotScanAnEarlierGatesWholeResultWhenTheTemporaryFolderIsInsideTheCheckedTree(): void
    {
        $home = $this->fakeGatesHome();
        $home->write('config/gates/run-gate.php', $this->dashedCommitsThenTheRealDashes());
        $tree = $this->emptySandbox();
        $tree->write('tmp/.keep', '');

        $check = new ProcessRunner()->run([PHP_BINARY, $home->root.'/bin/check', '--root='.$tree->root, '--only=commits,dashes'], $home->root, [GateSlots::VARIABLE => '1', 'TMPDIR' => $tree->root.'/tmp']);

        self::assertTrue(new CheckRun($check)->passed('dashes'), $check->output);
    }

    public function testAChildIgnoresAnInheritedHandOffWhenTheScratchFolderWouldBeInsideTheCheckedTree(): void
    {
        $home = $this->fakeGatesHome();
        $home->write('config/gates/run-gate.php', '<?php require '.var_export(dirname(__DIR__, 2).'/config/gates/run-gate.php', true).';');
        $tree = $this->emptySandbox();
        $tree->write('tmp/.keep', '');

        $check = new ProcessRunner()->run([PHP_BINARY, $home->root.'/bin/check', '--root='.$tree->root, '--only=dashes'], $home->root, [GateSlots::VARIABLE => '1', 'TMPDIR' => $tree->root.'/tmp', GateChildren::FULL_RESULT_VARIABLE => $tree->root.'/inherited.json']);

        self::assertTrue(new CheckRun($check)->passed('dashes'), $check->output);
        self::assertFileDoesNotExist($tree->root.'/inherited.json');
    }

    public function testAChildWritesItsWholeResultWhereTheHandOffNames(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();

        $child = $this->childIn($sandbox, [GateChildren::FULL_RESULT_VARIABLE => $sandbox->root.'/whole.json']);

        self::assertCount(12, $this->findingsIn((string) file_get_contents($sandbox->root.'/whole.json')));
        self::assertSame([1, '2'], [$child->exitCode, new CheckRun($child)->omittedOf('dashes')]);
    }

    public function testAChildStartedWithoutTheHandOffWritesNoWholeResult(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();

        $child = $this->childIn($sandbox, [GateChildren::FULL_RESULT_VARIABLE => false]);

        $notes = array_map(static fn (int $number): string => 'notes-'.$number.'.md', range(1, 12));
        self::assertSame([], array_values(array_diff((array) scandir($sandbox->root), ['.', '..', ...$notes])));
        self::assertSame(1, $child->exitCode, $child->output);
    }

    public function testAChildLeavesAFileAlreadyInPlaceOfItsWholeResult(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $sandbox->write('whole.json', 'kept before');

        $child = $this->childIn($sandbox, [GateChildren::FULL_RESULT_VARIABLE => $sandbox->root.'/whole.json']);

        self::assertSame('kept before', file_get_contents($sandbox->root.'/whole.json'));
        self::assertSame(1, $child->exitCode, $child->output);
    }

    public function testAChildCreatesNothingWhereADanglingLinkInPlaceOfItsWholeResultPoints(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $sandbox->symlink('whole.json', $sandbox->root.'/target.json');

        $child = $this->childIn($sandbox, [GateChildren::FULL_RESULT_VARIABLE => $sandbox->root.'/whole.json']);

        self::assertFileDoesNotExist($sandbox->root.'/target.json');
        self::assertSame(1, $child->exitCode, $child->output);
    }

    public function testACheckLeavesNoScratchFolderBehind(): void
    {
        $temporary = $this->emptySandbox();

        $check = new ProcessRunner()->run([PHP_BINARY, $this->fakeGatesHome()->root.'/bin/check', '--root='.$this->emptySandbox()->root, '--only=cs'], $temporary->root, [GateSlots::VARIABLE => '1', 'TMPDIR' => $temporary->root]);

        self::assertSame(0, $check->exitCode, $check->output);
        self::assertSame([], glob($temporary->root.'/intakt-gate-results-*'));
    }

    public function testAnInterruptedCheckLeavesNoScratchFolderBehind(): void
    {
        $home = $this->fakeGatesHome();
        $temporary = $this->emptySandbox();
        $check = new Process([PHP_BINARY, $home->root.'/bin/check', '--root='.$this->emptySandbox()->root, '--only=commits'], $home->root, [GateSlots::VARIABLE => '1', 'TMPDIR' => $temporary->root]);
        $check->start();
        $this->awaitFile($home->root.'/commits.pid');

        $check->signal(15);
        $check->wait();

        self::assertSame([], glob($temporary->root.'/intakt-gate-results-*'));
    }

    private function fakeGatesHome(): Sandbox
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('gate-children');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');

        return $home;
    }

    // Because the commits child must leave a long dash in its whole result before the real dashes gate scans the tree.
    private function dashedCommitsThenTheRealDashes(): string
    {
        $real = var_export(dirname(__DIR__, 2), true);

        return <<<PHP
            <?php
            if ('dashes' === \$argv[1]) {
                require {$real}.'/config/gates/run-gate.php';
            }
            require {$real}.'/vendor/autoload.php';
            \$result = Intakt\\Gates\\GateResult::failedBecause('commits', "subject \\u{2014} example");
            echo \$result->toJson(), "\\n";
            file_put_contents((string) getenv('INTAKT_GATE_FULL_RESULT'), \$result->toFullJson());
            exit(1);
            PHP;
    }

    // Because the child writes its process id once it runs, the signal must wait for it; the limit only ends a hang.
    private function awaitFile(string $file): void
    {
        $until = hrtime(true) + self::SAFETY_LIMIT_NANOSECONDS;

        while ('' === (string) @file_get_contents($file) && hrtime(true) < $until) {
            usleep(10000);
        }

        self::assertFileExists($file);
    }

    private function sandboxWithTwelveDashes(): Sandbox
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, 12) as $number) {
            $sandbox->write('notes-'.$number.'.md', "Plain \u{2014} text\n");
        }

        return $sandbox;
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function childIn(Sandbox $sandbox, array $environment): ProcessResult
    {
        return new ProcessRunner()->run([PHP_BINARY, dirname(__DIR__, 2).'/config/gates/run-gate.php', 'dashes', '--root='.$sandbox->root], $sandbox->root, $environment);
    }

    /**
     * @return list<mixed>
     */
    private function findingsIn(string $whole): array
    {
        $decoded = json_decode($whole, true);
        self::assertIsArray($decoded, $whole);
        self::assertIsArray($decoded['findings'] ?? null, $whole);

        return array_values($decoded['findings']);
    }
}
