<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use FilesystemIterator;
use Intakt\Gates\CheckCommand;
use Intakt\Gates\ChildCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\GateSlots;
use Intakt\Gates\JsonNode;
use Intakt\Gates\LowPriority;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RunningChild;
use Intakt\Gates\ScratchDirectory;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(FullReport::class)]
#[CoversClass(CheckCommand::class)]
#[CoversClass(GateChildren::class)]
#[CoversClass(ScratchDirectory::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(GateSlots::class)]
#[UsesClass(JsonNode::class)]
#[UsesClass(LowPriority::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(RunningChild::class)]
#[UsesClass(StopSignalForwarding::class)]
final class FullReportTest extends SandboxTestCase
{
    private const string NAME = '~^var/check/report-[0-9a-f]{16}\.json$~';

    public function testBinCheckWritesTheFullReportFileAndItsSummaryNamesIt(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $file = $this->summaryOf($run)->get('report')->text();
        self::assertMatchesRegularExpression(self::NAME, $file, $run->output());
        self::assertCount(12, $this->findingsIn($this->reportIn($sandbox->root.'/'.$file)));
    }

    public function testTheReportLeavesTheVerdictTheFailedListAndTheExitCodeOfAFailingCheck(): void
    {
        $run = $this->sandboxWithTwelveDashes()->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        self::assertSame(['fail', ['dashes']], $this->verdictOf($this->summaryOf($run)), $run->output());
        self::assertSame(1, $run->exitCode(), $run->output());
    }

    public function testTheReportLeavesTheVerdictAndTheExitCodeOfAPassingCheck(): void
    {
        $sandbox = $this->emptySandbox();

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        self::assertSame(['pass', []], $this->verdictOf($this->summaryOf($run)), $run->output());
        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertFileExists($sandbox->root.'/'.$this->summaryOf($run)->get('report')->text());
    }

    public function testBinCheckPrintsNoReportKeyAndKeepsItsVerdictWhenTheReportCannotBeWritten(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $sandbox->write('var/check', 'a file where the folder belongs');

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $summary = $this->summaryOf($run);
        self::assertSame(['result', 'failed'], array_keys($summary->entries()), $run->output());
        self::assertSame([['fail', ['dashes']], 1], [$this->verdictOf($summary), $run->exitCode()], $run->output());
    }

    public function testBinCheckWritesNoReportAndKeepsItsVerdictWhenVarIsALinkOutsideTheTree(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $outside = $this->emptySandbox();
        $sandbox->symlink('var', $outside->root);

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $this->assertNoReportAndTheSameVerdict($run, $outside);
    }

    public function testBinCheckWritesNoReportAndKeepsItsVerdictWhenVarCheckIsALinkOutsideTheTree(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $outside = $this->emptySandbox();
        mkdir($sandbox->root.'/var');
        $sandbox->symlink('var/check', $outside->root);

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $this->assertNoReportAndTheSameVerdict($run, $outside);
    }

    public function testBinCheckPrintsOnlyJsonAndNoReportKeyWhenTheTempDirectoryIsMissing(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $home = dirname(__DIR__, 2);
        $missing = $this->emptySandbox()->root.'/missing';

        $run = new CheckRun(new ProcessRunner()->run(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'sys_temp_dir='.$missing, $home.'/bin/check', '--root='.$sandbox->root, '--only=dashes'], $home, [GateSlots::VARIABLE => '1']));

        self::assertSame([], array_values(array_filter($run->lines(), static fn (string $line): bool => !json_validate($line))), $run->output());
        $summary = $this->summaryOf($run);
        self::assertSame(['result', 'failed'], array_keys($summary->entries()), $run->output());
        self::assertSame([['fail', ['dashes']], 1], [$this->verdictOf($summary), $run->exitCode()], $run->output());
        self::assertDirectoryDoesNotExist($sandbox->root.'/'.FullReport::FOLDER);
    }

    public function testAScratchEntryThatCannotBeRemovedStillLeavesTheReportAndItsVerdict(): void
    {
        $home = $this->homeWhoseChildLocksAScratchEntry();
        $temp = $this->emptySandbox();
        $tree = $this->emptySandbox();

        try {
            $run = new CheckRun(new ProcessRunner()->run(['php', $home->root.'/bin/check', '--root='.$tree->root, '--only=dashes'], $home->root, [GateSlots::VARIABLE => '1', 'TMPDIR' => $temp->root]));
        } finally {
            $locked = $this->unlockedFoldersIn($temp->root);
        }

        $summary = $this->summaryOf($run);
        self::assertNotSame(0, $locked, 'the child locked no scratch entry, so the cleanup could not fail');
        self::assertSame([['fail', ['dashes']], 1], [$this->verdictOf($summary), $run->exitCode()], $run->output());
        self::assertSame(['notes.md:1 locked'], $this->findingsIn($this->reportIn($tree->root.'/'.$summary->get('report')->text())));
    }

    public function testTheFullReportIsAsciiOnlyEvenWhenAFindingHoldsNonAsciiText(): void
    {
        $sandbox = $this->emptySandbox();

        $file = new FullReport($sandbox->root, [$this->reportOf(GateResult::failedBecause('dashes', "notes.md:1 \u{2014} here"))])->write();

        self::assertMatchesRegularExpression('~^[\x00-\x7F]*$~', (string) file_get_contents($sandbox->root.'/'.$file));
        self::assertSame(["notes.md:1 \u{2014} here"], $this->findingsIn($this->reportIn($sandbox->root.'/'.$file)));
    }

    public function testTwoReportsInTheSameTreeGetDifferentNamesAndNeitherOverwritesTheOther(): void
    {
        $sandbox = $this->emptySandbox();

        $first = new FullReport($sandbox->root, [$this->reportOf(GateResult::passed('dashes'))])->write();
        $second = new FullReport($sandbox->root, [$this->reportOf(GateResult::failedBecause('dashes', 'second'))])->write();

        self::assertNotSame($first, $second);
        self::assertSame([], $this->findingsIn($this->reportIn($sandbox->root.'/'.$first)));
        self::assertSame(['second'], $this->findingsIn($this->reportIn($sandbox->root.'/'.$second)));
    }

    public function testTheReportHoldsTheKeptWholeResultAndIsWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = $this->twelveFindings();

        $file = new FullReport($sandbox->root, [$this->reportOf($result)->keeping($result->toFullJson())])->write();

        $report = $this->reportIn($sandbox->root.'/'.$file);
        self::assertSame(['1', 12], [$report->get('whole')->text(), count($this->findingsIn($report))]);
    }

    public function testAFullReportOfAGateThatKeptAFailureAndThenCrashedHoldsTheCrash(): void
    {
        $sandbox = $this->emptySandbox();
        $crashed = GateReport::fromChild('dashes', new ProcessResult(139, '', 'Segmentation fault'));

        $file = new FullReport($sandbox->root, [$crashed->keeping(GateResult::failedBecause('dashes', 'notes.md:1 U+2014')->toFullJson())])->write();

        self::assertSame('{"whole":true,"gates":['.$crashed->line.']}'."\n", file_get_contents($sandbox->root.'/'.$file));
    }

    public function testAReportWithACutLineAndNoKeptResultIsNotWhole(): void
    {
        $sandbox = $this->emptySandbox();

        $file = new FullReport($sandbox->root, [$this->reportOf($this->twelveFindings())])->write();

        $report = $this->reportIn($sandbox->root.'/'.$file);
        self::assertSame(['', 10, '2'], [$report->get('whole')->text(), count($this->findingsIn($report)), $report->get('gates')->items()[0]->get('omitted')->text()]);
    }

    public function testAKeptResultWithANumberTooLargeForPhpIsNotKeptAndTheReportListsTheCutLine(): void
    {
        $sandbox = $this->emptySandbox();
        $kept = substr($this->twelveFindings()->toFullJson(), 0, -1).',"note":1e400}';
        $other = GateResult::failedBecause('comments', 'kept.php:1');

        $file = new FullReport($sandbox->root, [
            $this->reportOf($this->twelveFindings())->keeping($kept),
            GateReport::fromChild('comments', new ProcessResult(1, $other->toJson()."\n", ''))->keeping($other->toFullJson()),
        ])->write();

        self::assertSame('{"whole":false,"gates":['.$this->twelveFindings()->toJson().','.$other->toFullJson().']}'."\n", file_get_contents($sandbox->root.'/'.$file));
    }

    public function testAKeptResultWithANulPrefixedKeyIsCopiedByteForByteAndKeepsTheReportWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = GateResult::failedBecause('dashes', 'notes.md:1');
        $kept = substr($result->toFullJson(), 0, -1).',"note":{"\u0000key":"fixture","numeric":["value"]}}';
        $gate = $this->reportOf($result)->keeping($kept);
        self::assertSame($kept, $gate->kept);

        $file = new FullReport($sandbox->root, [$gate])->write();

        self::assertSame('{"whole":true,"gates":['.$kept.']}'."\n", file_get_contents($sandbox->root.'/'.$file));
    }

    public function testASummaryLineWithAKeyThatObjectsCannotHoldStillGivesAReportOfEveryGate(): void
    {
        $sandbox = $this->emptySandbox();
        $line = json_encode(['gate' => 'dashes', 'ok' => false, 'findings' => ['notes.md:1'], 'omitted' => 0, 'note' => ["\0key" => 'fixture']], GateResult::JSON_FLAGS);
        $first = GateReport::fromChild('dashes', new ProcessResult(1, $line."\n", ''));
        $other = GateResult::failedBecause('comments', 'kept.php:1');
        self::assertSame($line, $first->line);

        $file = new FullReport($sandbox->root, [$first, GateReport::fromChild('comments', new ProcessResult(1, $other->toJson()."\n", ''))])->write();

        self::assertNotNull($file);
        $gates = $this->reportIn($sandbox->root.'/'.$file)->get('gates')->items();
        self::assertSame([['notes.md:1'], ['kept.php:1'], "\0key"], [
            array_map(static fn (JsonNode $finding): string => $finding->text(), $gates[0]->get('findings')->items()),
            array_map(static fn (JsonNode $finding): string => $finding->text(), $gates[1]->get('findings')->items()),
            (string) array_key_first($gates[0]->get('note')->entries()),
        ]);
    }

    private function sandboxWithTwelveDashes(): Sandbox
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, 12) as $number) {
            $sandbox->write('notes-'.$number.'.md', "Plain \u{2014} text\n");
        }

        return $sandbox;
    }

    // Because the children run the home's own child entry, a home whose entry locks a folder inside its scratch folder forces the cleanup to fail without a seam in bin/check.
    private function homeWhoseChildLocksAScratchEntry(): Sandbox
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('locked-scratch');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');

        return $home;
    }

    // Because Symfony's remove() may have renamed the locked folder before it failed, every folder under the temp directory gets its mode back, so the sandbox can be removed.
    private function unlockedFoldersIn(string $temp): int
    {
        $locked = 0;
        $folders = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($folders as $folder) {
            if ($folder instanceof SplFileInfo && $folder->isDir() && !$folder->isWritable()) {
                chmod($folder->getPathname(), 0o700);
                ++$locked;
            }
        }

        return $locked;
    }

    private function twelveFindings(): GateResult
    {
        return GateResult::withFindings('dashes', array_map(static fn (int $number): string => 'notes-'.$number.'.md:1', range(1, 12)));
    }

    private function reportOf(GateResult $result): GateReport
    {
        return GateReport::fromChild('dashes', new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }

    private function assertNoReportAndTheSameVerdict(CheckRun $run, Sandbox $outside): void
    {
        $summary = $this->summaryOf($run);
        self::assertSame(['result', 'failed'], array_keys($summary->entries()), $run->output());
        self::assertSame([['fail', ['dashes']], 1], [$this->verdictOf($summary), $run->exitCode()], $run->output());
        self::assertSame([], array_values(array_diff((array) scandir($outside->root), ['.', '..'])), $run->output());
    }

    private function summaryOf(CheckRun $run): JsonNode
    {
        $summary = JsonNode::parse((string) array_last($run->lines()));
        self::assertInstanceOf(JsonNode::class, $summary, $run->output());

        return $summary;
    }

    /**
     * @return array{string, list<string>}
     */
    private function verdictOf(JsonNode $summary): array
    {
        return [$summary->get('result')->text(), array_map(static fn (JsonNode $gate): string => $gate->text(), $summary->get('failed')->items())];
    }

    private function reportIn(string $file): JsonNode
    {
        $report = JsonNode::parse((string) file_get_contents($file));
        self::assertInstanceOf(JsonNode::class, $report, $file);

        return $report;
    }

    /**
     * @return list<string>
     */
    private function findingsIn(JsonNode $report): array
    {
        $gate = $report->get('gates')->items()[0] ?? null;
        self::assertInstanceOf(JsonNode::class, $gate);

        return array_map(static fn (JsonNode $finding): string => $finding->text(), $gate->get('findings')->items());
    }
}
