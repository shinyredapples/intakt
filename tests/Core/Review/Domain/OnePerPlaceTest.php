<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FindingKind;
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
        self::assertSame(['F2'], [...$act[0]->merged]);
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

    public function testTheMergedEntryKeepsTheTitleAndKindOfTheFirstReviewerAlsoWhenTheOtherIsMoreSevere(): void
    {
        $reported = [$this->titled('F1', 7, 'The discount is added', FindingKind::Logic), $this->titled('F2', 7, 'The total is never checked', FindingKind::MissingTest)->withSeverity(Severity::P1)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2']);

        self::assertSame([['F1', 'P1', 'The discount is added', FindingKind::Logic]], $this->titlesOf($act));
    }

    public function testAnEntryMergedByCauseKeepsTheTitleAndKindOfTheFindingItIsNamedAfter(): void
    {
        $reported = [$this->titled('F1', 7, 'The discount is added', FindingKind::Logic), $this->titled('F4', 9, 'A secret reaches the log', FindingKind::Security)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F4'], ['F4' => 'F1']);

        self::assertSame([['F1', 'P2', 'The discount is added', FindingKind::Logic]], $this->titlesOf($act));
    }

    public function testAnEntryWithoutATitleOrKindTakesNoneFromAFindingItMerged(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->titled('F2', 7, 'The discount is added', FindingKind::Logic)], ['F1', 'F2']);

        self::assertSame([['F1', 'P2', null, null]], $this->titlesOf($act));
    }

    public function testTheMergedEntryKeepsItsOwnJudgeNote(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7)->withJudgeNote('First note.'), $this->finding('F2', 'src/A.php', 7)->withJudgeNote('Second note.')], ['F1', 'F2']);

        self::assertSame('First note.', $act[0]->judgeNote());
    }

    public function testTheMergedEntryTakesTheJudgeNoteOfAFindingItMergedWhenItHasNone(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)->withJudgeNote('Rejected: unreachable.')], ['F1', 'F2']);

        self::assertSame('Rejected: unreachable.', $act[0]->judgeNote());
    }

    public function testAnEntryMergedByCauseAcrossLinesTakesTheNoteOfTheFindingItMerged(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 9)->withJudgeNote('Rejected: unreachable.')], ['F1', 'F2'], ['F2' => 'F1']);

        self::assertCount(1, $act);
        self::assertSame(['F2'], [...$act[0]->merged]);
        self::assertSame(7, $act[0]->line);
        self::assertSame('Rejected: unreachable.', $act[0]->judgeNote());
    }

    public function testAMergedEntryWithoutAnyJudgeNoteHasNone(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)], ['F1', 'F2']);

        self::assertNull($act[0]->judgeNote());
    }

    public function testThreeFindingsAtTheSamePlaceNameEveryMergedIdInPanelOrder(): void
    {
        $reported = [$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7), $this->finding('F3', 'src/A.php', 7)];

        $act = new OnePerPlace()->actList($reported, ['F3', 'F1', 'F2']);

        self::assertSame(['F2', 'F3'], [...$act[0]->merged]);
    }

    public function testAFindingReportedTwiceWithTheSameIdGivesOneEntryWithNoMergedIds(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F1', 'src/A.php', 7)], ['F1']);

        self::assertCount(1, $act);
        self::assertSame([], [...$act[0]->merged]);
    }

    public function testAMergedIdReportedTwiceIsListedOnce(): void
    {
        $reported = [$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2']);

        self::assertSame(['F2'], [...$act[0]->merged]);
    }

    public function testMergedIdsCarriedByAFindingAreListedOnceWithoutTheEntrysOwnId(): void
    {
        $first = $this->finding('F1', 'src/A.php', 7);
        $second = $this->finding('F2', 'src/A.php', 7)->withMerged(new FindingIds('F1', 'F2', 'F3', 'F3'));

        $act = new OnePerPlace()->actList([$first, $second, $second], ['F1', 'F2']);

        self::assertCount(1, $act);
        self::assertSame(['F2', 'F3'], [...$act[0]->merged]);
    }

    public function testARepeatedFindingThatCarriesItsOwnIdAndDuplicatesKeepsOnlyTheValidIds(): void
    {
        $legacy = $this->finding('F1', 'src/A.php', 7)->withMerged(new FindingIds('F1', 'F3', 'F3'));

        $act = new OnePerPlace()->actList([$legacy, $legacy], ['F1']);

        self::assertCount(1, $act);
        self::assertSame(['F3'], [...$act[0]->merged]);
    }

    public function testFindingsAtOnePlaceThatTheJudgeMarksDifferentlyStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 7)->withSeverity(Severity::P1)], ['F1']);

        self::assertCount(1, $act);
        self::assertSame('F1', $act[0]->id);
        self::assertSame(Severity::P2, $act[0]->severity);
        self::assertSame([], [...$act[0]->merged]);
    }

    public function testFindingsAtDifferentLinesOfOneFileStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 8)], ['F1', 'F2']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
        self::assertSame([[], []], array_map(static fn (Finding $entry): array => [...$entry->merged], $act));
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
        self::assertSame(['F3'], [...$act[0]->merged]);
    }

    public function testTwoActFindingsTheJudgeGivesOneCauseNearbyInOneFileGiveOneEntryWithTheHigherSeverity(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12)->withSeverity(Severity::P1), $this->finding('F2', 'src/B.php', 1), $this->finding('F3', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F3' => 'F1']);

        self::assertSame([['F1', 'P1', ['F3']], ['F2', 'P2', []]], array_map(static fn (Finding $entry): array => [$entry->id, $entry->severity->value, [...$entry->merged]], $act));
    }

    public function testALaterFindingOfHigherSeverityRaisesTheEntryOfItsCause(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F4', 'src/A.php', 9)->withSeverity(Severity::P1)], ['F1', 'F4'], ['F4' => 'F1']);

        self::assertSame([['F1', 'P1', ['F4']]], array_map(static fn (Finding $entry): array => [$entry->id, $entry->severity->value, [...$entry->merged]], $act));
    }

    public function testAFindingNamedAsTheCauseOfALaterOneTakesItIntoItsEntry(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 9)], ['F1', 'F2'], ['F1' => 'F2']);

        self::assertSame([['F1', ['F2']]], array_map(static fn (Finding $entry): array => [$entry->id, [...$entry->merged]], $act));
    }

    public function testTwoActFindingsWithoutALinkAtDifferentLinesStaySeparate(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 8)], ['F1', 'F2'], ['F3' => 'F1']);

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
    }

    public function testAFindingAtThePlaceOfOneMergedThroughACauseLinkJoinsTheSameEntry(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12), $this->finding('F2', 'src/discount.js', 14), $this->finding('F3', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F1']);

        self::assertSame([['F1', ['F2', 'F3']]], array_map(static fn (Finding $entry): array => [$entry->id, [...$entry->merged]], $act));
    }

    public function testAFindingNamingACauseOffTheActListStaysOnItsOwn(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', 8)], ['F2'], ['F2' => 'F1']);

        self::assertSame([['F2', []]], array_map(static fn (Finding $entry): array => [$entry->id, [...$entry->merged]], $act));
    }

    public function testAFindingLinkedToAnEarlierEntryBringsTheEntryAtItsOwnPlaceWithIt(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12), $this->finding('F2', 'src/discount.js', 14), $this->finding('F3', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F3' => 'F1']);

        self::assertSame([['F1', ['F2', 'F3']]], $this->idsOf($act));
    }

    public function testTwoFindingsAtOnePlaceGiveTheSameGroupInEitherPanelOrder(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12), $this->finding('F3', 'src/discount.js', 14), $this->finding('F2', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F3' => 'F1']);

        self::assertSame([['F1', ['F3', 'F2']]], $this->idsOf($act));
    }

    public function testALaterEntryOfHigherSeverityRaisesTheEntryItIsJoinedInto(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12), $this->finding('F2', 'src/discount.js', 14)->withSeverity(Severity::P1), $this->finding('F3', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F3' => 'F1']);

        self::assertSame([['F1', 'P1', ['F2', 'F3']]], array_map(static fn (Finding $entry): array => [$entry->id, $entry->severity->value, [...$entry->merged]], $act));
    }

    public function testAJoinedEntryKeepsTheTextOfItsFirstFindingInPanelOrder(): void
    {
        $reported = [$this->atLineSeven('F1', 'First.'), $this->finding('F2', 'src/A.php', 9), $this->finding('F3', 'src/A.php', 9)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F3' => 'F1']);

        self::assertSame(['First.'], array_column($act, 'text'));
    }

    public function testFindingsLinkedInAChainWithinTheNearbyLinesAreOneEntry(): void
    {
        $reported = [$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 5), $this->finding('F3', 'src/New.php', 8)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F1', 'F3' => 'F2']);

        self::assertSame([['F1', ['F2', 'F3']]], $this->idsOf($act));
    }

    public function testFindingsLinkedInAChainAcrossAnEarlierLineWithinTheNearbyLinesAreOneEntry(): void
    {
        $reported = [$this->finding('F1', 'src/discount.js', 12), $this->finding('F2', 'src/discount.js', 17), $this->finding('F3', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F3', 'F3' => 'F1']);

        self::assertSame([['F1', ['F2', 'F3']]], $this->idsOf($act));
    }

    public function testALinkThatWouldStretchItsGroupOneLineFurtherThanTheNearbyLinesIsIgnored(): void
    {
        $reported = [$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 8), $this->finding('F3', 'src/New.php', 9)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F1', 'F3' => 'F2']);

        self::assertSame([['F1', ['F2']], ['F3', []]], $this->idsOf($act));
    }

    public function testAChainOfLinksEachWithinTheNearbyLinesKeepsTheFarFindingAsItsOwnEntryWithItsText(): void
    {
        $reported = [$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 8), new Finding('F3', Severity::P2, 'src/New.php', 13, null, 'Far defect.', FixableBy::Builder, false, null, null)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F1', 'F3' => 'F2']);

        self::assertSame([['F1', ['F2'], 'Broken.'], ['F3', [], 'Far defect.']], array_map(static fn (Finding $entry): array => [$entry->id, [...$entry->merged], $entry->text], $act));
    }

    public function testLinksAreTriedInThePanelOrderOfTheLinkingFinding(): void
    {
        $reported = [$this->finding('F3', 'src/New.php', 13), $this->finding('F2', 'src/New.php', 8), $this->finding('F1', 'src/New.php', 3)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3'], ['F2' => 'F1', 'F3' => 'F2']);

        self::assertSame([['F3', ['F2']], ['F1', []]], $this->idsOf($act));
    }

    public function testALinkIsIgnoredWhenTheFindingsAtItsPlaceWouldStretchTheGroupTooFarButThePlaceStaysMerged(): void
    {
        $reported = [$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 8), $this->finding('F3', 'src/New.php', 8), $this->finding('F4', 'src/New.php', 13)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3', 'F4'], ['F2' => 'F1', 'F4' => 'F3']);

        self::assertSame([['F1', ['F2', 'F3']], ['F4', []]], $this->idsOf($act));
    }

    public function testALinkIgnoredForReachingTooFarLeavesAnotherCauseMerged(): void
    {
        $reported = [$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 8), $this->finding('F3', 'src/New.php', 13), $this->finding('F4', 'src/discount.js', 12), $this->finding('F5', 'src/discount.js', 14)];

        $act = new OnePerPlace()->actList($reported, ['F1', 'F2', 'F3', 'F4', 'F5'], ['F2' => 'F1', 'F3' => 'F2', 'F5' => 'F4']);

        self::assertSame([['F1', ['F2']], ['F3', []], ['F4', ['F5']]], $this->idsOf($act));
    }

    public function testALinkBetweenFindingsSixLinesApartIsNotMerged(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/New.php', 3), $this->finding('F2', 'src/New.php', 9)], ['F1', 'F2'], ['F2' => 'F1']);

        self::assertSame([['F1', []], ['F2', []]], $this->idsOf($act));
    }

    public function testALinkBetweenFindingsInTwoFilesIsNotMerged(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/B.php', 7)], ['F1', 'F2'], ['F2' => 'F1']);

        self::assertSame([['F1', []], ['F2', []]], $this->idsOf($act));
    }

    public function testALinkToAFindingWithoutALineIsNotMerged(): void
    {
        $act = new OnePerPlace()->actList([$this->finding('F1', 'src/A.php', 7), $this->finding('F2', 'src/A.php', null)], ['F1', 'F2'], ['F2' => 'F1']);

        self::assertSame([['F1', []], ['F2', []]], $this->idsOf($act));
    }

    /**
     * @param list<Finding> $act
     *
     * @return list<array{string, list<string>}>
     */
    private function idsOf(array $act): array
    {
        return array_map(static fn (Finding $entry): array => [$entry->id, [...$entry->merged]], $act);
    }

    private function finding(string $id, ?string $file, ?int $line): Finding
    {
        return new Finding($id, Severity::P2, $file, $line, null, 'Broken.', FixableBy::Builder, false, null, null);
    }

    private function atLineSeven(string $id, string $text): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 7, null, $text, FixableBy::Builder, false, null, null);
    }

    /**
     * @param list<Finding> $act
     *
     * @return list<array{string, string, ?string, ?FindingKind}>
     */
    private function titlesOf(array $act): array
    {
        return array_map(static fn (Finding $entry): array => [$entry->id, $entry->severity->value, $entry->title, $entry->kind], $act);
    }

    private function titled(string $id, int $line, string $title, FindingKind $kind): Finding
    {
        return new Finding(
            $id,
            Severity::P2,
            'src/A.php',
            $line,
            null,
            'Broken.',
            FixableBy::Builder,
            false,
            null,
            null,
            title: $title,
            kind: $kind,
        );
    }
}
