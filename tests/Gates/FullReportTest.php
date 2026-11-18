<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FullReport::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
final class FullReportTest extends SandboxTestCase
{
    private const int DASHED_LINES = 12;

    public function testTheSummaryLineNamesTheFullReportFile(): void
    {
        $sandbox = $this->sandboxWithDashedLines();

        $run = $sandbox->check('--only=dashes');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['result' => 'fail', 'failed' => ['dashes'], 'report' => FullReport::PATH], $this->summaryOf($run));
        self::assertFileExists($sandbox->root.'/'.FullReport::PATH);
    }

    public function testTheFullReportHoldsEveryFindingTheGateLineOmits(): void
    {
        $sandbox = $this->sandboxWithDashedLines();

        $run = $sandbox->check('--only=dashes');

        self::assertSame((string) (self::DASHED_LINES - 10), $run->omittedOf('dashes'), $run->output());
        self::assertCount(self::DASHED_LINES, $this->reportedGate($sandbox, 'dashes')['findings']);
    }

    public function testAPassingCheckStillWritesTheReportAndNamesIt(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('notes.txt', "plain text\n");

        $run = $sandbox->check('--only=dashes');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame(FullReport::PATH, $this->summaryOf($run)['report'] ?? null);
        self::assertSame(['gate' => 'dashes', 'ok' => true, 'findings' => [], 'note' => 'commits skipped, no git history in this tree'], $this->reportedGate($sandbox, 'dashes'));
    }

    public function testAReportThatCannotBeWrittenIsNotNamedAndLeavesTheVerdictAlone(): void
    {
        $sandbox = $this->sandboxWithDashedLines();
        $sandbox->write('var/check', "a file where the folder belongs\n");

        $run = $sandbox->check('--only=dashes');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['result' => 'fail', 'failed' => ['dashes']], $this->summaryOf($run));
    }

    public function testALongFindingIsKeptWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = GateResult::failedBecause('cs', str_repeat('x', 1000));
        $report = FullReport::start();

        FullReport::keep($result, $this->fileFor($report, 'cs'));
        $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [$this->reportOf($result)]);

        self::assertSame([str_repeat('x', 1000)], $this->reportedGate($sandbox, 'cs')['findings']);
    }

    public function testAGateWhoseChildCrashedIsReportedFromItsLineAsFailed(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [GateReport::fromChild('cs', new ProcessResult(139, '', 'killed'))]);

        $gate = $this->reportedGate($sandbox, 'cs');
        self::assertFalse($gate['ok']);
        self::assertStringContainsString('gate process ended with exit code 139', (string) json_encode($gate['findings']));
    }

    public function testAKeptResultThatDisagreesWithTheGateLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        FullReport::keep(GateResult::passed('cs'), $this->fileFor($report, 'cs'));
        $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [$this->reportOf(GateResult::failedBecause('cs', 'bad'))]);

        self::assertSame(['bad'], $this->reportedGate($sandbox, 'cs')['findings']);
    }

    public function testAKeptResultOfAnotherGateIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        FullReport::keep(GateResult::passedBecause('tests', 'other gate'), $this->fileFor($report, 'cs'));
        $report->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], [$this->reportOf(GateResult::passed('cs'))]);

        self::assertSame(['gate' => 'cs', 'ok' => true, 'findings' => [], 'omitted' => 0], $this->reportedGate($sandbox, 'cs'));
    }

    public function testAChildTakesItsFileOutOfTheEnvironmentItsOwnChildrenInherit(): void
    {
        putenv(FullReport::VARIABLE.'=/somewhere/cs.json');

        $file = FullReport::fileAskedFor();

        self::assertSame('/somewhere/cs.json', $file);
        self::assertFalse(getenv(FullReport::VARIABLE));
    }

    public function testAChildAskedForNoFileKeepsNothing(): void
    {
        putenv(FullReport::VARIABLE);

        self::assertNull(FullReport::fileAskedFor());
    }

    private function sandboxWithDashedLines(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('notes.txt', str_repeat("a \u{2014} b\n", self::DASHED_LINES));

        return $sandbox;
    }

    private function fileFor(FullReport $report, string $gate): string
    {
        $file = $report->environmentFor($gate)[FullReport::VARIABLE];
        self::assertIsString($file);

        return $file;
    }

    private function reportOf(GateResult $result): GateReport
    {
        return GateReport::fromChild($result->gate, new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function summaryOf(CheckRun $run): array
    {
        $summary = json_decode((string) array_last($run->lines()), true);
        self::assertIsArray($summary, $run->output());

        return $summary;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function reportedGate(Sandbox $sandbox, string $gate): array
    {
        $report = json_decode((string) file_get_contents($sandbox->root.'/'.FullReport::PATH), true);
        self::assertIsArray($report);
        self::assertIsArray($report['gates']);

        foreach ($report['gates'] as $entry) {
            if (is_array($entry) && $gate === ($entry['gate'] ?? null)) {
                return $entry;
            }
        }

        self::fail('no '.$gate.' in the report');
    }
}
