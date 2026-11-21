<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessResult::class)]
final class GateReportKeptResultTest extends TestCase
{
    public function testAKeptResultWhoseFindingsGiveTheLineIsAccepted(): void
    {
        $result = GateResult::withFindings('dashes', $this->elevenFindings());

        $report = $this->reportOf($result)->keeping($result->toFullJson());

        self::assertSame($result->toFullJson(), $report->kept);
    }

    public function testAChildThatKeepsNothingWithOneFindingOf160WideCharactersIsTreatedAsCut(): void
    {
        $report = $this->reportOf(GateResult::withFindings('dashes', [str_repeat('界', 160)]));

        self::assertNull($report->kept);
        self::assertTrue($report->lineIsCut());
    }

    public function testUnderAnotherInternalEncodingA160WideCharacterFindingIsStillTreatedAsCut(): void
    {
        $encoding = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');

        try {
            $report = $this->reportOf(GateResult::withFindings('dashes', [str_repeat('界', 160)]));
        } finally {
            mb_internal_encoding($encoding);
        }

        self::assertTrue($report->lineIsCut());
    }

    public function testAChildThatKeepsNothingWithOneShortFindingIsNotCut(): void
    {
        self::assertFalse($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->lineIsCut());
    }

    public function testALineThatOmitsFindingsIsCut(): void
    {
        self::assertTrue($this->reportOf(GateResult::withFindings('dashes', $this->elevenFindings()))->lineIsCut());
    }

    public function testWhitespaceTheLineFoldsIsNoCut(): void
    {
        self::assertFalse($this->reportOf(GateResult::withFindings('dashes', ["notes.md:1\n\n   U+2014"]))->lineIsCut());
    }

    public function testWithASpaceAsSubstituteCharacterAKeptResultWithABrokenByteNextToASpaceIsAccepted(): void
    {
        $substitute = mb_substitute_character();
        mb_substitute_character(32);

        try {
            $result = GateResult::withFindings('dashes', ["notes.md:1 broken \xFFbyte", ...$this->elevenFindings(2, 11)]);
            $kept = $result->toFullJson();
            $report = $this->reportOf($result)->keeping($kept);
        } finally {
            mb_substitute_character($substitute);
        }

        self::assertSame($kept, $report->kept);
    }

    public function testAKeptResultWithItsFindingsUnderTheKeyZeroIsAccepted(): void
    {
        $kept = '{"gate":"dashes","ok":false,"findings":{"0":"notes.md:1 U+2014"},"omitted":0}';

        $report = $this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept);

        self::assertSame($kept, $report->kept);
    }

    public function testAKeptResultWithItsFindingsUnderOtherKeysFallsBackToTheLine(): void
    {
        $kept = '{"gate":"dashes","ok":false,"findings":{"first":"notes.md:1 U+2014"},"omitted":0}';

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept)->kept);
    }

    public function testAKeptResultOfAnotherGateFallsBackToTheLine(): void
    {
        $kept = GateResult::withFindings('cs', ['notes.md:1 U+2014'])->toFullJson();

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept)->kept);
    }

    public function testAKeptResultWithOtherFindingsThanItsLineFallsBackToTheLine(): void
    {
        $kept = GateResult::withFindings('dashes', $this->elevenFindings(1, 10))->toFullJson();

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', $this->elevenFindings()))->keeping($kept)->kept);
    }

    public function testAKeptResultWithTheLinesFindingsButAPassingOkFallsBackToTheLine(): void
    {
        $kept = '{"gate":"dashes","ok":true,"findings":["notes.md:1 U+2014"],"omitted":0}';

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept)->kept);
    }

    public function testAKeptResultWithNoFindingsButAFailingOkFallsBackToAPassingLine(): void
    {
        $kept = '{"gate":"dashes","ok":false,"findings":[],"omitted":0}';

        self::assertNull($this->reportOf(GateResult::passed('dashes'))->keeping($kept)->kept);
    }

    public function testAKeptResultWithoutAnOkFallsBackToTheLine(): void
    {
        $kept = '{"gate":"dashes","findings":["notes.md:1 U+2014"],"omitted":0}';

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept)->kept);
    }

    public function testAKeptResultThatClaimsOmittedFindingsFallsBackToTheLine(): void
    {
        $kept = '{"gate":"dashes","ok":false,"findings":["notes.md:1 U+2014"],"omitted":3}';

        self::assertNull($this->reportOf(GateResult::withFindings('dashes', ['notes.md:1 U+2014']))->keeping($kept)->kept);
    }

    public function testAKeptPassingResultIsAcceptedForAPassingLine(): void
    {
        $result = GateResult::passed('dashes');

        self::assertSame($result->toFullJson(), $this->reportOf($result)->keeping($result->toFullJson())->kept);
    }

    public function testAKeptResultThatIsNoJsonFallsBackToTheLine(): void
    {
        self::assertNull($this->reportOf(GateResult::passed('dashes'))->keeping('{"gate":')->kept);
    }

    public function testAKeptResultLeavesTheLineAndTheVerdictAlone(): void
    {
        $result = GateResult::withFindings('dashes', $this->elevenFindings());
        $report = $this->reportOf($result);

        $keeping = $report->keeping($result->toFullJson());

        self::assertSame([$report->line, false], [$keeping->line, $keeping->isPassing()]);
    }

    public function testAChildThatKeptAFailureAndThenCrashedWithoutALineIsReportedWithTheCrash(): void
    {
        $saved = GateResult::withFindings('dashes', ['notes.md:1 U+2014']);

        $report = GateReport::fromChild('dashes', new ProcessResult(139, '', 'Segmentation fault'))->keeping($saved->toFullJson());

        self::assertNull($report->kept);
        self::assertStringContainsString('exit code 139', $report->line);
    }

    public function testAKeptResultIsRefusedForABuiltLineEvenWhenItsFindingsEqualTheLines(): void
    {
        $built = GateReport::fromChild('dashes', new ProcessResult(139, '', 'Segmentation fault'));
        $finding = 'gate process ended with exit code 139 and no valid result line: Segmentation fault';
        self::assertSame(GateResult::failedBecause('dashes', $finding)->toJson(), $built->line);

        self::assertNull($built->keeping(GateResult::failedBecause('dashes', $finding)->toFullJson())->kept);
    }

    public function testAGateWhoseValidLineCameWithTheWrongExitCodeKeepsNoSavedResult(): void
    {
        $saved = GateResult::withFindings('dashes', ['notes.md:1 U+2014']);

        $report = GateReport::fromChild('dashes', new ProcessResult(139, $saved->toJson()."\n", ''))->keeping($saved->toFullJson());

        self::assertNull($report->kept);
        self::assertStringContainsString('exit code 139', $report->line);
    }

    /**
     * @return list<string>
     */
    private function elevenFindings(int $first = 1, int $last = 11): array
    {
        return array_map(static fn (int $number): string => 'notes.md:'.$number.' U+2014', range($first, $last));
    }

    private function reportOf(GateResult $result): GateReport
    {
        return GateReport::fromChild($result->gate, new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }
}
