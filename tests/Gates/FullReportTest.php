<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\JsonNode;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ScratchDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FullReport::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(JsonNode::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ScratchDirectory::class)]
final class FullReportTest extends SandboxTestCase
{
    private const array SUMMARY = ['result' => 'fail', 'failed' => ['dashes']];

    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if (null !== $this->scratch && is_dir($this->scratch.'/stuck')) {
            chmod($this->scratch.'/stuck', 0o700);
        }

        if (null !== $this->scratch) {
            new Filesystem()->remove($this->scratch);
        }

        parent::tearDown();
    }

    public function testAScratchEntryThatCannotBeRemovedStillLeavesTheReportFile(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        mkdir($this->scratch.'/stuck');
        touch($this->scratch.'/stuck/entry');
        chmod($this->scratch.'/stuck', 0o500);

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['one finding'])]);

        self::assertIsString($name);
        $report = $this->reportIn($tree->root.'/'.$name);
        self::assertSame('fail', $report['result'] ?? null);
        self::assertSame(['dashes'], $report['failed'] ?? null);
    }

    public function testTheReportHoldsEveryFindingAChildKept(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        $findings = array_map(static fn (int $number): string => 'finding '.$number, range(1, 15));
        $this->keepFor($fullReport, GateResult::withFindings('dashes', $findings));

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', $findings)]);

        self::assertSame($findings, $this->findingsIn($tree->root.'/'.$name));
    }

    public function testAFindingTheLineOmitsWithInvalidUtf8IsStillKeptInTheReport(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        $findings = [...array_map(static fn (int $number): string => 'finding '.$number, range(1, 10)), "tool output \xff\xfe"];
        $result = GateResult::withFindings('dashes', $findings);
        $this->keepFor($fullReport, $result);

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', $findings)]);

        self::assertSame([...array_slice($findings, 0, 10), "tool output \u{FFFD}\u{FFFD}"], $this->findingsIn($tree->root.'/'.$name));
    }

    public function testNoReportIsNamedWhenAChildFailedToKeepFindingsItsLineOmits(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        $findings = array_map(strval(...), range(1, 12));
        mkdir((string) $fullReport->environmentFor('dashes')[FullReport::VARIABLE]);
        $this->keepFor($fullReport, GateResult::withFindings('dashes', $findings));

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', $findings)]);

        self::assertNull($name);
        self::assertSame([], glob($tree->root.'/report-*.json'));
    }

    public function testTheReportNameCarriesTheHashOfItsContent(): void
    {
        $tree = $this->emptySandbox();

        $name = $this->startedReport()->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['x'])]);

        self::assertIsString($name);
        self::assertSame('report-'.substr(hash('sha256', (string) file_get_contents($tree->root.'/'.$name)), 0, 16).'.json', $name);
    }

    public function testAGateWhoseChildKeptNothingIsReportedByItsLine(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['from the line'])]);

        self::assertSame(['from the line'], $this->findingsIn($tree->root.'/'.$name));
    }

    public function testAKeptResultThatContradictsTheGateLineIsReplacedByTheLine(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        $this->keepFor($fullReport, GateResult::passed('dashes'));

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['from the line'])]);

        self::assertSame(['from the line'], $this->findingsIn($tree->root.'/'.$name));
    }

    public function testTheScratchFolderIsGoneOnceTheReportIsWritten(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();

        $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['x'])]);

        self::assertDirectoryDoesNotExist((string) $this->scratch);
    }

    public function testTheScratchFolderIsOnlyForItsOwner(): void
    {
        $this->startedReport();

        self::assertSame(0o700, fileperms((string) $this->scratch) & 0o777);
    }

    private function startedReport(): FullReport
    {
        $fullReport = FullReport::start();
        $path = $fullReport->environmentFor('dashes')[FullReport::VARIABLE];
        self::assertIsString($path);
        $this->scratch = dirname($path);

        return $fullReport;
    }

    private function keepFor(FullReport $fullReport, GateResult $result): void
    {
        FullReport::keep($result, array_filter($fullReport->environmentFor($result->gate), is_string(...)));
    }

    /**
     * @param list<string> $findings
     */
    private function failingLine(string $gate, array $findings): GateReport
    {
        return GateReport::fromChild($gate, new ProcessResult(1, GateResult::withFindings($gate, $findings)->toJson()."\n", ''));
    }

    /**
     * @return list<string>
     */
    private function findingsIn(string $file): array
    {
        $report = JsonNode::parse((string) file_get_contents($file));
        self::assertInstanceOf(JsonNode::class, $report);

        return array_map(static fn (JsonNode $finding): string => $finding->text(), array_first($report->get('gates')->items())?->get('findings')->items() ?? []);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function reportIn(string $file): array
    {
        $report = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($report);

        return $report;
    }
}
