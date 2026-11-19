<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ScratchDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FullReport::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
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

    public function testAScratchEntryThatCannotBeRemovedStillLeavesTheReportAndItsVerdict(): void
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

        self::assertSame($findings, $this->reportIn($tree->root.'/'.$name)['gates'][0]['findings'] ?? null);
    }

    public function testAGateWhoseChildKeptNothingIsReportedByItsLine(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['from the line'])]);

        self::assertSame(['from the line'], $this->reportIn($tree->root.'/'.$name)['gates'][0]['findings'] ?? null);
    }

    public function testAKeptResultThatContradictsTheGateLineIsReplacedByTheLine(): void
    {
        $tree = $this->emptySandbox();
        $fullReport = $this->startedReport();
        $this->keepFor($fullReport, GateResult::passed('dashes'));

        $name = $fullReport->writeTo($tree->root, self::SUMMARY, [$this->failingLine('dashes', ['from the line'])]);

        self::assertSame(['from the line'], $this->reportIn($tree->root.'/'.$name)['gates'][0]['findings'] ?? null);
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
     * @return array<array-key, mixed>
     */
    private function reportIn(string $file): array
    {
        $report = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($report);

        return $report;
    }
}
