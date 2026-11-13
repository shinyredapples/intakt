<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\OnePerPlace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OnePerPlace::class)]
#[UsesClass(Finding::class)]
final class OnePerPlaceTest extends TestCase
{
    public function testTwoActFindingsAtTheSamePlaceGiveOneEntryThatNamesBothIds(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)], ['F1', 'F2']);

        self::assertCount(1, $act);
        self::assertSame('F1', $act[0]->id);
        self::assertSame(['F2'], $act[0]->merged);
    }

    public function testTheMergedEntryKeepsTheHighestPriority(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)->withSeverity(Severity::P1)], ['F1', 'F2']);

        self::assertSame(Severity::P1, $act[0]->severity);
    }

    public function testTheMergedEntryKeepsTheTextOfTheFirstReviewer(): void
    {
        $act = new OnePerPlace()->actList([$this->atLineSeven('F1', 'First.'), $this->atLineSeven('F2', 'Second.')->withSeverity(Severity::P1)], ['F1', 'F2']);

        self::assertSame('First.', $act[0]->text);
    }

    public function testThreeFindingsAtTheSamePlaceNameEveryMergedIdInPanelOrder(): void
    {
        $reported = [$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7), $this->finding('F3', 'src/A.php', 7)];

        $act = new OnePerPlace()->actList($reported, ['F3', 'F1', 'F2']);

        self::assertSame(['F2', 'F3'], $act[0]->merged);
    }

    public function testFindingsAtOnePlaceThatTheJudgeMarksDifferentlyStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)->withSeverity(Severity::P1)], ['F1']);

        self::assertCount(1, $act);
        self::assertSame('F1', $act[0]->id);
        self::assertSame(Severity::P2, $act[0]->severity);
        self::assertSame([], $act[0]->merged);
    }

    public function testFindingsAtDifferentLinesOfOneFileStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 8)], ['F1', 'F2']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
        self::assertSame([[], []], array_column($act, 'merged'));
    }

    public function testFindingsAtTheSameLineOfDifferentFilesStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/B.php', 7)], ['F1', 'F2']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
    }

    public function testFindingsWithoutALineStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', null), $this->finding('F2', 'src/A.php', null)], ['F1', 'F2']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
    }

    public function testFindingsWithoutAFileStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', null, 7), $this->finding('F2', null, 7)], ['F1', 'F2']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
    }

    public function testEntriesKeepTheOrderInWhichTheirPlaceWasFirstReported(): void
    {
        $reported = [$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/B.php', 1), $this->finding('F3', 'src/A.php', 7)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
        self::assertSame(['F3'], $act[0]->merged);
    }

    private function finding(string $id, ?string $file, ?int $line): Finding
    {
        return new Finding($id, Severity::P2, $file, $line, null, 'Broken.', FixableBy::Builder, false, null, null);
    }

    private function atLineSeven(string $id, string $text): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 7, null, $text, FixableBy::Builder, false, null, null);
    }
}
