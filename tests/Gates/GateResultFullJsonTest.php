<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(GateResult::class)]
final class GateResultFullJsonTest extends TestCase
{
    public function testTheWholeResultKeepsEveryFindingUncut(): void
    {
        $findings = [str_repeat('a', 400), ...array_map(static fn (int $number): string => 'finding '.$number, range(1, 11))];

        $whole = $this->decoded(GateResult::withFindings('dashes', $findings)->toFullJson());

        self::assertSame(['gate' => 'dashes', 'ok' => false, 'findings' => $findings, 'omitted' => 0], $whole);
    }

    public function testTheWholeResultKeepsTheNoteOfTheLine(): void
    {
        $whole = $this->decoded(GateResult::passedBecause('tests', 'gates pass skipped')->toFullJson());

        self::assertSame(['gate' => 'tests', 'ok' => true, 'findings' => [], 'omitted' => 0, 'note' => 'gates pass skipped'], $whole);
    }

    public function testABrokenByteReadsTheSameInTheWholeResultAndInTheLine(): void
    {
        $result = GateResult::withFindings('dashes', ["notes.md:1 a\xFFb"]);

        self::assertSame($this->firstFindingShownIn($result->toJson()), $this->firstFindingShownIn($result->toFullJson()));
    }

    public function testAFindingOf160WideCharactersCountsAsCutOnceTheLineShortensIt(): void
    {
        $shown = $this->firstFindingShownIn(GateResult::withFindings('dashes', [str_repeat('界', 160)])->toJson());

        self::assertTrue(GateResult::isCut($shown));
    }

    public function testAFindingOneColumnShortOfTheCutIsNotCut(): void
    {
        self::assertFalse(GateResult::isCut(str_repeat('a', GateResult::MAXIMUM_FINDING_LENGTH - 4)));
    }

    public function testAFindingRightAtTheCutCountsAsCut(): void
    {
        self::assertTrue(GateResult::isCut(str_repeat('a', GateResult::MAXIMUM_FINDING_LENGTH - 3)));
    }

    public function testWithoutMbstringTheMeasureIsTheCharacterCount(): void
    {
        self::assertSame('false', $this->withoutMbstring('var_export(Intakt\Gates\GateResult::isCut($argv[2]));', str_repeat('界', 160)));
    }

    public function testWithoutMbstringABrokenByteIsReplacedSoTheWholeResultStaysJson(): void
    {
        $finding = $this->withoutMbstring('echo json_decode(Intakt\Gates\GateResult::withFindings("x", [$argv[2]])->toFullJson(), true)["findings"][0];', "a\xFFb");

        self::assertSame("a\u{FFFD}b", $finding);
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function firstFindingShownIn(string $json): string
    {
        $findings = $this->decoded($json)['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertIsString($findings[0] ?? null);

        return $findings[0];
    }

    // Because the Composer autoloader brings Symfony's mbstring polyfill, the class is loaded alone, as on a PHP whose only gap is mbstring.
    private function withoutMbstring(string $code, string $finding): string
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'disable_functions=mb_strimwidth,mb_strwidth,mb_scrub',
            '-r',
            'require $argv[1]; '.$code,
            dirname(__DIR__, 2).'/config/gates/src/GateResult.php',
            $finding,
        ]);
        $process->mustRun();

        return $process->getOutput();
    }
}
