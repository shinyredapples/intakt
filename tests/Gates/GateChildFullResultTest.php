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
    public function testTheParentCollectsTheWholeResultEachChildKeeps(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $slots = GateSlots::chosen('1', 1, 1);
        self::assertInstanceOf(GateSlots::class, $slots);

        $reports = new GateChildren(dirname(__DIR__, 2), $sandbox->root, $slots, [])->reportsOf(['dashes'], ['--root='.$sandbox->root, '--only=dashes']);

        self::assertCount(12, $this->findingsIn((string) $reports[0]->kept));
        self::assertStringEndsWith('"omitted":2}', $reports[0]->line);
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
