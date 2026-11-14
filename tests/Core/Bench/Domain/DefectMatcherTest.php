<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\Domain;

use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefectMatcher::class)]
#[UsesClass(PlantedDefect::class)]
final class DefectMatcherTest extends TestCase
{
    #[DataProvider('linesInsideTheRange')]
    public function testAFindingOnTheSameFileAndALineInsideTheRangeMatchesTheDefect(int $line): void
    {
        self::assertTrue(new DefectMatcher()->matches(self::finding('src/a.js', $line), self::defect()));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function linesInsideTheRange(): array
    {
        return ['first line' => [4], 'middle line' => [5], 'last line' => [6]];
    }

    #[DataProvider('linesOutsideTheRange')]
    public function testAFindingJustOutsideTheRangeDoesNotMatch(int $line): void
    {
        self::assertFalse(new DefectMatcher()->matches(self::finding('src/a.js', $line), self::defect()));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function linesOutsideTheRange(): array
    {
        return ['one line before' => [3], 'one line past' => [7]];
    }

    public function testAFindingOnAnotherFileWithTheSameLineDoesNotMatch(): void
    {
        self::assertFalse(new DefectMatcher()->matches(self::finding('src/b.js', 5), self::defect()));
    }

    public function testAFindingWithoutAFileNeverMatches(): void
    {
        self::assertFalse(new DefectMatcher()->matches(self::finding(null, 5), self::defect()));
    }

    public function testAFindingWithoutALineNeverMatches(): void
    {
        self::assertFalse(new DefectMatcher()->matches(self::finding('src/a.js', null), self::defect()));
    }

    public static function finding(?string $file, ?int $line): Finding
    {
        return new Finding('F1', Severity::P2, $file, $line, null, 'Something is wrong.', FixableBy::Builder, false, null, null);
    }

    private static function defect(): PlantedDefect
    {
        return new PlantedDefect('d1', 'src/a.js', 4, 6, 'A defect.', Severity::P2);
    }
}
