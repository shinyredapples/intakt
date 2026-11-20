<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ChildCommand;
use Intakt\Gates\FullReport;
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

#[CoversClass(FullReport::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(GateChildren::class)]
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
final class FullReportTest extends SandboxTestCase
{
    private const string NAME = '~^var/check/report-[0-9a-f]{16}\.json$~';

    public function testBinCheckWritesTheFullReportFileAndItsSummaryNamesIt(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $file = $this->reportNamedIn($run);
        self::assertMatchesRegularExpression(self::NAME, $file, $run->output());
        self::assertCount(12, $this->reportIn($sandbox->root.'/'.$file)['gates'][0]['findings']);
    }

    public function testTheReportLeavesTheVerdictTheFailedListAndTheExitCodeOfAFailingCheck(): void
    {
        $run = $this->sandboxWithTwelveDashes()->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        $summary = $this->summaryOf($run);
        self::assertSame(['fail', ['dashes']], [$summary['result'], $summary['failed']], $run->output());
        self::assertSame(1, $run->exitCode(), $run->output());
    }

    public function testTheReportLeavesTheVerdictAndTheExitCodeOfAPassingCheck(): void
    {
        $sandbox = $this->emptySandbox();

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        self::assertSame(['result' => 'pass', 'failed' => []], array_diff_key($this->summaryOf($run), ['report' => true]), $run->output());
        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertFileExists($sandbox->root.'/'.$this->reportNamedIn($run));
    }

    public function testBinCheckPrintsNoReportKeyAndKeepsItsVerdictWhenTheReportCannotBeWritten(): void
    {
        $sandbox = $this->sandboxWithTwelveDashes();
        $sandbox->write('var/check', 'a file where the folder belongs');

        $run = $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');

        self::assertSame(['result' => 'fail', 'failed' => ['dashes']], $this->summaryOf($run), $run->output());
        self::assertSame(1, $run->exitCode(), $run->output());
    }

    public function testTheFullReportIsAsciiOnlyEvenWhenAFindingHoldsNonAsciiText(): void
    {
        $sandbox = $this->emptySandbox();

        $file = new FullReport($sandbox->root, [$this->reportOf(GateResult::failedBecause('dashes', "notes.md:1 \u{2014} here"))])->write();

        $contents = (string) file_get_contents($sandbox->root.'/'.$file);
        self::assertMatchesRegularExpression('~^[\x00-\x7F]*$~', $contents);
        self::assertSame(["notes.md:1 \u{2014} here"], $this->reportIn($sandbox->root.'/'.$file)['gates'][0]['findings']);
    }

    public function testTwoReportsInTheSameTreeGetDifferentNamesAndNeitherOverwritesTheOther(): void
    {
        $sandbox = $this->emptySandbox();

        $first = new FullReport($sandbox->root, [$this->reportOf(GateResult::passed('dashes'))])->write();
        $second = new FullReport($sandbox->root, [$this->reportOf(GateResult::failedBecause('dashes', 'second'))])->write();

        self::assertNotSame($first, $second);
        self::assertSame([], $this->reportIn($sandbox->root.'/'.$first)['gates'][0]['findings']);
        self::assertSame(['second'], $this->reportIn($sandbox->root.'/'.$second)['gates'][0]['findings']);
    }

    public function testTheReportHoldsTheKeptWholeResultAndIsWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = GateResult::withFindings('dashes', array_map(static fn (int $number): string => 'notes-'.$number.'.md:1', range(1, 12)));

        $file = new FullReport($sandbox->root, [$this->reportOf($result)->keeping($result->toFullJson())])->write();

        $report = $this->reportIn($sandbox->root.'/'.$file);
        self::assertSame([true, 12], [$report['whole'], count($report['gates'][0]['findings'])]);
    }

    public function testAReportWithACutLineAndNoKeptResultIsNotWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = GateResult::withFindings('dashes', array_map(static fn (int $number): string => 'notes-'.$number.'.md:1', range(1, 12)));

        $file = new FullReport($sandbox->root, [$this->reportOf($result)])->write();

        $report = $this->reportIn($sandbox->root.'/'.$file);
        self::assertSame([false, 10, 2], [$report['whole'], count($report['gates'][0]['findings']), $report['gates'][0]['omitted']]);
    }

    private function sandboxWithTwelveDashes(): Sandbox
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, 12) as $number) {
            $sandbox->write('notes-'.$number.'.md', "Plain \u{2014} text\n");
        }

        return $sandbox;
    }

    private function reportOf(GateResult $result): GateReport
    {
        return GateReport::fromChild('dashes', new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }

    private function reportNamedIn(CheckRun $run): string
    {
        $report = $this->summaryOf($run)['report'] ?? null;
        self::assertIsString($report, $run->output());

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOf(CheckRun $run): array
    {
        $lines = $run->lines();
        $summary = json_decode((string) array_last($lines), true);
        self::assertIsArray($summary, $run->output());

        return $summary;
    }

    /**
     * @return array{whole: bool, gates: list<array{findings: list<string>, omitted: int}>}
     */
    private function reportIn(string $file): array
    {
        $report = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($report, $file);
        self::assertIsBool($report['whole'] ?? null, $file);
        self::assertIsArray($report['gates'] ?? null, $file);

        /** @var array{whole: bool, gates: list<array{findings: list<string>, omitted: int}>} $report */
        return $report;
    }
}
