<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FullReport::class)]
#[CoversClass(CheckCommand::class)]
#[CoversClass(GateChildren::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
final class FullReportTest extends SandboxTestCase
{
    private const int DASHED_LINES = 12;

    private const string REPORT_NAME = '~^var/check/report-[0-9a-f]{16}\.json$~';

    public function testTheSummaryLineNamesTheFullReportFile(): void
    {
        $sandbox = $this->sandboxWithDashedLines();

        $run = $sandbox->check('--only=dashes');

        self::assertSame(1, $run->exitCode(), $run->output());
        $summary = $this->summaryOf($run);
        self::assertSame(['result' => 'fail', 'failed' => ['dashes']], array_slice($summary, 0, 2));
        self::assertMatchesRegularExpression(self::REPORT_NAME, (string) $summary['report']);
        self::assertFileExists($sandbox->root.'/'.$summary['report']);
    }

    public function testTheFullReportHoldsEveryFindingTheGateLineOmits(): void
    {
        $sandbox = $this->sandboxWithDashedLines();

        $run = $sandbox->check('--only=dashes');

        self::assertSame((string) (self::DASHED_LINES - 10), $run->omittedOf('dashes'), $run->output());
        $findings = $this->reportedGate($sandbox, $this->reportNamedBy($run), 'dashes')['findings'];
        self::assertIsArray($findings);
        self::assertCount(self::DASHED_LINES, $findings);
    }

    public function testAPassingCheckStillWritesTheReportAndNamesIt(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('notes.txt', "plain text\n");

        $run = $sandbox->check('--only=dashes');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame(['gate' => 'dashes', 'ok' => true, 'findings' => [], 'note' => 'commits skipped, no git history in this tree'], $this->reportedGate($sandbox, $this->reportNamedBy($run), 'dashes'));
    }

    public function testAReportThatCannotBeWrittenIsNotNamedAndLeavesTheVerdictAlone(): void
    {
        $sandbox = $this->sandboxWithDashedLines();
        $sandbox->write('var/check', "a file where the folder belongs\n");

        $run = $sandbox->check('--only=dashes');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['result' => 'fail', 'failed' => ['dashes']], $this->summaryOf($run));
    }

    public function testTwoChecksOfTheSameTreeKeepTheirOwnReports(): void
    {
        $sandbox = $this->emptySandbox();
        $first = FullReport::start();
        $second = FullReport::start();

        $firstName = $first->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['dashes']], []);
        $secondName = $second->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], []);

        self::assertNotNull($firstName);
        self::assertNotSame($firstName, $secondName);
        self::assertSame('fail', $this->reportAt($sandbox, $firstName)['result']);
    }

    public function testOnlyTheNewestReportsAreKept(): void
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, FullReport::KEPT_REPORTS + 5) as $older) {
            $sandbox->write(sprintf('var/check/report-%016x.json', $older), "{}\n");
            touch($sandbox->root.sprintf('/var/check/report-%016x.json', $older), 1000 + $older);
        }

        $name = FullReport::start()->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], []);

        self::assertCount(FullReport::KEPT_REPORTS, glob($sandbox->root.'/var/check/report-*.json') ?: []);
        self::assertFileExists($sandbox->root.'/'.$name);
        self::assertFileDoesNotExist($sandbox->root.sprintf('/var/check/report-%016x.json', 6));
        self::assertFileExists($sandbox->root.sprintf('/var/check/report-%016x.json', 7));
    }

    public function testAReportIsNotWrittenThroughALinkAtItsPath(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('outside/existing.json', 'keep this');
        $sandbox->write('tree/var/check/.keep', '');
        $report = FullReport::start();
        $sandbox->symlink('tree/'.$report->path, $sandbox->root.'/outside/existing.json');

        $name = $report->writeTo($sandbox->root.'/tree', ['result' => 'pass', 'failed' => []], []);

        self::assertNull($name);
        self::assertStringEqualsFile($sandbox->root.'/outside/existing.json', 'keep this');
    }

    public function testAReportIsNotWrittenIntoALinkedFolder(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('outside/.keep', '');
        $sandbox->write('tree/.keep', '');
        $sandbox->symlink('tree/var', $sandbox->root.'/outside');

        $name = FullReport::start()->writeTo($sandbox->root.'/tree', ['result' => 'pass', 'failed' => []], []);

        self::assertNull($name);
        self::assertSame(['.', '..', '.keep'], scandir($sandbox->root.'/outside'));
    }

    public function testALongFindingIsKeptWhole(): void
    {
        $sandbox = $this->emptySandbox();
        $result = GateResult::failedBecause('cs', str_repeat('x', 1000));
        $report = FullReport::start();

        FullReport::keep($result, $this->fileFor($report, 'cs'));
        $name = $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [$this->reportOf($result)]);

        self::assertSame([str_repeat('x', 1000)], $this->reportedGate($sandbox, $name, 'cs')['findings']);
    }

    public function testAGateWhoseChildCrashedIsReportedFromItsLineAsFailed(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        $name = $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [GateReport::fromChild('cs', new ProcessResult(139, '', 'killed'))]);

        $gate = $this->reportedGate($sandbox, $name, 'cs');
        self::assertFalse($gate['ok']);
        self::assertStringContainsString('gate process ended with exit code 139', (string) json_encode($gate['findings']));
    }

    public function testAKeptResultThatDisagreesWithTheGateLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        FullReport::keep(GateResult::passed('cs'), $this->fileFor($report, 'cs'));
        $name = $report->writeTo($sandbox->root, ['result' => 'fail', 'failed' => ['cs']], [$this->reportOf(GateResult::failedBecause('cs', 'bad'))]);

        self::assertSame(['bad'], $this->reportedGate($sandbox, $name, 'cs')['findings']);
    }

    public function testAKeptResultOfAnotherGateIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();

        FullReport::keep(GateResult::passedBecause('tests', 'other gate'), $this->fileFor($report, 'cs'));
        $name = $report->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], [$this->reportOf(GateResult::passed('cs'))]);

        self::assertSame(['gate' => 'cs', 'ok' => true, 'findings' => [], 'omitted' => 0], $this->reportedGate($sandbox, $name, 'cs'));
    }

    public function testAKeptResultWithANoteThatIsNoTextIsReportedAsItsLine(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();
        file_put_contents($this->fileFor($report, 'tests'), '{"gate":"tests","ok":true,"findings":[],"note":1e999}');

        $name = $report->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], [$this->reportOf(GateResult::passed('tests'))]);

        self::assertSame(['gate' => 'tests', 'ok' => true, 'findings' => [], 'omitted' => 0], $this->reportedGate($sandbox, $name, 'tests'));
    }

    public function testAKeptResultWithSlowestSecondsThatAreNoNumberIsReportedAsItsLine(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();
        file_put_contents($this->fileFor($report, 'tests'), '{"gate":"tests","ok":true,"findings":[],"slowest":[{"file":"a.php","seconds":1e999}]}');

        $name = $report->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], [$this->reportOf(GateResult::passed('tests'))]);

        self::assertSame(['gate' => 'tests', 'ok' => true, 'findings' => [], 'omitted' => 0], $this->reportedGate($sandbox, $name, 'tests'));
    }

    public function testAKeptResultWithWellFormedSlowestTestsIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $report = FullReport::start();
        $kept = ['gate' => 'tests', 'ok' => true, 'findings' => [], 'note' => 'ran', 'slowest' => [['file' => 'a.php', 'seconds' => 1.5]]];
        file_put_contents($this->fileFor($report, 'tests'), (string) json_encode($kept));

        $name = $report->writeTo($sandbox->root, ['result' => 'pass', 'failed' => []], [$this->reportOf(GateResult::passed('tests'))]);

        self::assertSame($kept, $this->reportedGate($sandbox, $name, 'tests'));
    }

    public function testAStoppedCheckLeavesNoKeptResultsBehind(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/gates/run-gate.php', "<?php\nfile_put_contents(__DIR__.'/ready', (string) getenv('".FullReport::VARIABLE."'));\nsleep(30);\n");
        $sandbox->write('stopped.php', "<?php\nrequire '".dirname(__DIR__, 2)."/vendor/autoload.php';\nnew Intakt\\Gates\\GateChildren(\$argv[1], \$argv[1], Intakt\\Gates\\GateSlots::chosen('1', 1, 1), [], Intakt\\Gates\\FullReport::start())->reportsOf(['dashes'], []);\n");
        $process = proc_open([PHP_BINARY, $sandbox->root.'/stopped.php', $sandbox->root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $sandbox->root);
        self::assertNotFalse($process);
        $ready = $sandbox->root.'/config/gates/ready';

        for ($attempt = 0; $attempt < 1000 && (!is_file($ready) || '' === file_get_contents($ready)); ++$attempt) {
            usleep(10000);
        }

        proc_terminate($process, SIGTERM);
        proc_close($process);

        $scratch = dirname((string) file_get_contents($ready));
        self::assertStringContainsString('intakt-check-results', $scratch);
        self::assertDirectoryDoesNotExist($scratch);
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

    private function reportNamedBy(CheckRun $run): string
    {
        $name = $this->summaryOf($run)['report'] ?? null;
        self::assertIsString($name, $run->output());

        return $name;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function reportAt(Sandbox $sandbox, ?string $name): array
    {
        self::assertNotNull($name);
        $report = json_decode((string) file_get_contents($sandbox->root.'/'.$name), true);
        self::assertIsArray($report);

        return $report;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function reportedGate(Sandbox $sandbox, ?string $name, string $gate): array
    {
        $gates = $this->reportAt($sandbox, $name)['gates'];
        self::assertIsArray($gates);

        foreach ($gates as $entry) {
            if (is_array($entry) && $gate === ($entry['gate'] ?? null)) {
                return $entry;
            }
        }

        self::fail('no '.$gate.' in the report');
    }
}
