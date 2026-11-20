<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GateResult::class)]
final class GateResultWidthTest extends TestCase
{
    public function testAWideCharacterMeasuresTwoColumns(): void
    {
        self::assertSame(4, GateResult::widthOf("\u{4E00}\u{4E00}"));
    }

    public function testAFindingOfWideCharactersCutForTheLineMeasuresAtLeastTheLimitMinusThree(): void
    {
        $line = json_decode(GateResult::failedBecause('cs', str_repeat("\u{4E00}", 160))->toJson(), true);
        self::assertIsArray($line);
        self::assertIsArray($line['findings']);
        self::assertIsString($line['findings'][0]);

        self::assertGreaterThanOrEqual(GateResult::MAXIMUM_FINDING_LENGTH - 3, GateResult::widthOf($line['findings'][0]));
        self::assertLessThanOrEqual(GateResult::MAXIMUM_FINDING_LENGTH, GateResult::widthOf($line['findings'][0]));
    }

    public function testTheFullJsonKeepsEveryFindingUncut(): void
    {
        $findings = [str_repeat('x', 1000), ...array_fill(0, 11, 'y')];

        $full = json_decode(GateResult::withFindings('cs', $findings)->toFullJson(), true);

        self::assertSame(['gate' => 'cs', 'ok' => false, 'findings' => $findings], $full);
    }

    public function testAShortFindingWithABrokenByteGivesALineWithTheByteReplaced(): void
    {
        $line = json_decode(GateResult::failedBecause('dashes', "file-\xFF.md:1: U+2014")->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($line);

        self::assertSame(['file-?.md:1: U+2014'], $line['findings']);
    }

    public function testTheFullJsonOfAFindingWithABrokenByteHasTheByteReplacedAsInTheLine(): void
    {
        $full = json_decode(GateResult::withFindings('dashes', [...array_fill(0, 10, 'valid finding'), "file-\xFF.md:1: U+2014"])->toFullJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($full);
        self::assertIsArray($full['findings']);

        self::assertSame('file-?.md:1: U+2014', $full['findings'][10]);
    }
}
