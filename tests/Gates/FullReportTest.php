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

#[CoversClass(FullReport::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(GateChildren::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(GateSlots::class)]
#[UsesClass(JsonNode::class)]
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

    public function testAReportWithACutLineAndNoKeptResultIsNotWhole(): void
    {
        $sandbox = $this->emptySandbox();

        $file = new FullReport($sandbox->root, [$this->reportOf($this->twelveFindings())])->write();

        $report = $this->reportIn($sandbox->root.'/'.$file);
        self::assertSame(['', 10, '2'], [$report->get('whole')->text(), count($this->findingsIn($report)), $report->get('gates')->items()[0]->get('omitted')->text()]);
    }

    private function sandboxWithTwelveDashes(): Sandbox
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, 12) as $number) {
            $sandbox->write('notes-'.$number.'.md', "Plain \u{2014} text\n");
        }

        return $sandbox;
    }

    private function twelveFindings(): GateResult
    {
        return GateResult::withFindings('dashes', array_map(static fn (int $number): string => 'notes-'.$number.'.md:1', range(1, 12)));
    }

    private function reportOf(GateResult $result): GateReport
    {
        return GateReport::fromChild('dashes', new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
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
