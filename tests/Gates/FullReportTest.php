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

#[CoversClass(FullReport::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(ScratchDirectory::class)]
final class FullReportTest extends SandboxTestCase
{
    private ?Sandbox $root = null;

    public function testAKeptResultHoldsEveryFindingPastTheTenOfItsLine(): void
    {
        $result = GateResult::withFindings('cs', $this->numbered(25));
        $results = $this->resultsKeeping('cs', $result->toFullJson());

        $report = $this->reportOf($results, $this->lineOf($result));

        self::assertSame($this->numbered(25), $report['findings']);
        self::assertArrayNotHasKey('omitted', $report);
    }

    public function testAChildKeepsAResultWhoseEleventhFindingHasABrokenByteAndTheReportHoldsEveryFinding(): void
    {
        $findings = [...array_fill(0, 10, 'valid finding'), "file-\xFF.md:1: U+2014"];
        $result = GateResult::withFindings('dashes', $findings);
        $results = $this->resultsKeeping();

        putenv(FullReport::VARIABLE.'='.$results->path.'/dashes.json');

        try {
            FullReport::keep($result);
        } finally {
            putenv(FullReport::VARIABLE);
        }

        $report = $this->reportOf($results, $this->lineOf($result));

        self::assertSame([...array_fill(0, 10, 'valid finding'), 'file-?.md:1: U+2014'], $report['findings']);
    }

    public function testAKeptResultWithFewerFindingsThanItsLineAccountsForNamesNoReport(): void
    {
        $results = $this->resultsKeeping('cs', GateResult::withFindings('cs', $this->numbered(3))->toFullJson());

        $named = $this->fullReportIn($results)->writtenFor([$this->lineOf(GateResult::withFindings('cs', $this->numbered(25)))]);

        self::assertSame([], $named);
    }

    public function testAChildThatKeepsNothingAndHasOneFindingOf160WideCharactersNamesNoReport(): void
    {
        $results = $this->resultsKeeping();

        $named = $this->fullReportIn($results)->writtenFor([$this->lineOf(GateResult::failedBecause('cs', str_repeat("\u{4E00}", 160)))]);

        self::assertSame([], $named);
    }

    public function testAChildThatKeepsNothingHasItsWholeLineInTheReport(): void
    {
        $results = $this->resultsKeeping();

        $report = $this->reportOf($results, $this->lineOf(GateResult::failedBecause('cs', "caf\u{E9} is wrong")));

        self::assertSame(['gate' => 'cs', 'ok' => false, 'findings' => ["caf\u{E9} is wrong"]], $report);
    }

    public function testAChildThatKeepsNothingAndHasAFindingAtTheCutWidthNamesNoReport(): void
    {
        $results = $this->resultsKeeping();

        $named = $this->fullReportIn($results)->writtenFor([$this->lineOf(GateResult::failedBecause('cs', str_repeat('x', GateResult::MAXIMUM_FINDING_LENGTH - 3)))]);

        self::assertSame([], $named);
    }

    public function testAChildThatKeepsNothingAndHasAFindingJustBelowTheCutWidthHasItInTheReport(): void
    {
        $finding = str_repeat('x', GateResult::MAXIMUM_FINDING_LENGTH - 4);

        $report = $this->reportOf($this->resultsKeeping(), $this->lineOf(GateResult::failedBecause('cs', $finding)));

        self::assertSame([$finding], $report['findings']);
    }

    public function testKeptFindingsWrittenAsAnObjectWithTheKeyZeroCountAsTheFindings(): void
    {
        $results = $this->resultsKeeping('cs', '{"gate":"cs","ok":false,"findings":{"0":"kept finding"}}');

        $report = $this->reportOf($results, $this->lineOf(GateResult::failedBecause('cs', 'kept finding')));

        self::assertSame(['kept finding'], $report['findings']);
    }

    public function testKeptFindingsWrittenAsAnObjectWithAnotherKeyFallBackToTheLine(): void
    {
        $results = $this->resultsKeeping('cs', '{"gate":"cs","ok":false,"findings":{"1":"kept finding"}}');

        $report = $this->reportOf($results, $this->lineOf(GateResult::failedBecause('cs', 'line finding')));

        self::assertSame(['line finding'], $report['findings']);
    }

    public function testAResultKeptForAnotherGateFallsBackToTheLine(): void
    {
        $results = $this->resultsKeeping('cs', GateResult::failedBecause('rector', 'other gate')->toFullJson());

        $report = $this->reportOf($results, $this->lineOf(GateResult::failedBecause('cs', 'line finding')));

        self::assertSame(['line finding'], $report['findings']);
    }

    public function testTheReportIsWrittenInAsciiUnderTheCheckFolderWithARandomName(): void
    {
        $results = $this->resultsKeeping();

        $named = $this->fullReportIn($results)->writtenFor([$this->lineOf(GateResult::failedBecause('cs', "caf\u{E9}"))]);

        self::assertMatchesRegularExpression('#^var/check/report-[0-9a-f]{16}\.json$#', $named['report'] ?? '');
        self::assertMatchesRegularExpression('/^[\x00-\x7F]*$/', (string) file_get_contents($this->sandbox()->root.'/'.($named['report'] ?? '')));
    }

    public function testWritingTheReportRemovesTheScratchFolder(): void
    {
        $results = $this->resultsKeeping('cs', GateResult::passed('cs')->toFullJson());

        $this->fullReportIn($results)->writtenFor([$this->lineOf(GateResult::passed('cs'))]);

        self::assertDirectoryDoesNotExist($results->path);
    }

    public function testNoScratchFolderNamesNoReport(): void
    {
        $named = new FullReport($this->sandbox()->root, null)->writtenFor([$this->lineOf(GateResult::passed('cs'))]);

        self::assertSame([], $named);
    }

    public function testAChildIsToldWhereToKeepItsResultOnlyWhenThereIsAScratchFolder(): void
    {
        $results = $this->resultsKeeping();

        self::assertSame([FullReport::VARIABLE => $results->path.'/cs.json'], FullReport::environmentFor($results, 'cs'));
        self::assertSame([FullReport::VARIABLE => false], FullReport::environmentFor(null, 'cs'));
        $results->remove();
    }

    /**
     * @return list<string>
     */
    private function numbered(int $count): array
    {
        return array_map(static fn (int $number): string => 'finding '.$number, range(1, $count));
    }

    private function resultsKeeping(string $gate = '', string $kept = ''): ScratchDirectory
    {
        $results = ScratchDirectory::create('intakt-full-report-test');
        self::assertInstanceOf(ScratchDirectory::class, $results);

        if ('' !== $gate) {
            file_put_contents($results->path.'/'.$gate.'.json', $kept);
        }

        return $results;
    }

    private function lineOf(GateResult $result): GateReport
    {
        return GateReport::fromChild($result->gate, new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }

    private function fullReportIn(ScratchDirectory $results): FullReport
    {
        return new FullReport($this->sandbox()->root, $results);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function reportOf(ScratchDirectory $results, GateReport $report): array
    {
        $named = $this->fullReportIn($results)->writtenFor([$report]);
        self::assertArrayHasKey('report', $named);

        $written = json_decode((string) file_get_contents($this->sandbox()->root.'/'.($named['report'] ?? '')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($written);
        self::assertIsArray($written['gates']);
        self::assertCount(1, $written['gates']);
        self::assertIsArray($written['gates'][0]);

        return $written['gates'][0];
    }

    private function sandbox(): Sandbox
    {
        return $this->root ??= $this->emptySandbox();
    }
}
