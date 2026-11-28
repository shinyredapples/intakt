<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeNotes;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\SameCause;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudgedFindings::class)]
#[CoversClass(FollowUp::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(SameCause::class)]
#[UsesClass(JudgeNotes::class)]
final class JudgedFindingsTest extends TestCase
{
    public function testEveryJudgedFindingIsKeptWhateverItsMark(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act, 'b' => Mark::Defer, 'c' => Mark::Reject]), $this->findings('a', 'b', 'c', 'unmarked'));

        self::assertEquals($this->findings('a', 'b', 'c', 'unmarked'), $judged->findings);
    }

    public function testTheActListHoldsOnlyFindingsMarkedAct(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act, 'b' => Mark::Defer, 'c' => Mark::Reject]), $this->findings('a', 'b', 'c'));

        self::assertEquals($this->findings('a'), $judged->actList);
    }

    public function testADeferredFindingBecomesAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act, 'b' => Mark::Defer]), $this->findings('a', 'b'));

        self::assertEquals([new FollowUp($this->findings('b')[0])], $judged->followUps);
    }

    public function testARejectedFindingIsNeitherOnTheActListNorAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject]), $this->findings('a'));

        self::assertSame([], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testOnlyAP3TheJudgeMarkedRejectIsAmongTheRejected(): void
    {
        $blocking = new Finding('p2', Severity::P2, null, null, null, 'Text.', FixableBy::Builder, false, null, null);

        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject, 'b' => Mark::Defer, 'c' => Mark::Act, 'p2' => Mark::Reject]), [...$this->findings('a', 'b', 'c', 'unmarked'), $blocking]);

        self::assertEquals($this->findings('a'), $judged->rejected);
    }

    public function testAfterAnInvalidJudgeAnswerEveryBlockingFindingIsOnTheActListAndNoFollowUpIsMade(): void
    {
        $findings = [$this->finding('a', Severity::P1), $this->finding('b', Severity::P2), $this->finding('c', Severity::P3)];

        $judged = JudgedFindings::of(Judgement::everyBlockingFindingActs($findings), $findings);

        self::assertEquals([$findings[0], $findings[1]], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAFindingWithoutAMarkIsNeitherOnTheActListNorAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), $this->findings('a', 'unmarked'));

        self::assertEquals($this->findings('a'), $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAP1RejectedByTheJudgeStaysOnTheActList(): void
    {
        $finding = $this->finding('a', Severity::P1);

        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject]), [$finding]);

        self::assertEquals([$finding], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAP2DeferredByTheJudgeStaysOnTheActListAndIsNoFollowUp(): void
    {
        $finding = $this->finding('a', Severity::P2);

        $judged = JudgedFindings::of($this->marks(['a' => Mark::Defer]), [$finding]);

        self::assertEquals([$finding], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testJudgedFindingsKeepTheDroppedFindingsTheyAreGiven(): void
    {
        $dropped = [DroppedFinding::of($this->finding('gone', Severity::P2), 'Names no file.')];

        $judged = JudgedFindings::of($this->marks([]), $this->findings('a'), $dropped);

        self::assertSame($dropped, $judged->dropped);
    }

    public function testAP2WithoutAMarkStaysOnTheActList(): void
    {
        $finding = $this->finding('a', Severity::P2);

        self::assertEquals([$finding], JudgedFindings::of($this->marks([]), [$finding])->actList);
    }

    public function testTheJudgesNoteOnARejectedP1IsKeptAsTheJudgeGaveIt(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Reject, null, "  Not reachable.\n")]), [$this->finding('a', Severity::P1)]);

        self::assertSame(['a' => "  Not reachable.\n"], iterator_to_array($judged->notes));
    }

    public function testTheJudgesNoteOnADeferredP2IsKept(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Defer, null, 'Needs its own design.')]), [$this->finding('a', Severity::P2)]);

        self::assertSame(['a' => 'Needs its own design.'], iterator_to_array($judged->notes));
    }

    public function testASameCauseLinkBetweenTwoGivenFindingsIsKept(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Act), new JudgedFinding('b', Mark::Act, 'a')]), $this->findings('a', 'b'));

        self::assertSame(['b' => 'a'], iterator_to_array($judged->sameCause));
    }

    public function testASameCauseLinkToAFindingNotGivenIsDropped(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('b', Mark::Act, 'ghost')]), $this->findings('b'));

        self::assertSame([], iterator_to_array($judged->sameCause));
    }

    public function testASameCauseLinkOfAFindingToItselfIsDropped(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('b', Mark::Act, 'b')]), $this->findings('b'));

        self::assertSame([], iterator_to_array($judged->sameCause));
    }

    public function testANoteOnAP1MarkedActOrOnAP3IsNotKept(): void
    {
        $judgement = new Judgement([new JudgedFinding('a', Mark::Act, null, 'Real.'), new JudgedFinding('b', Mark::Reject, null, 'A nit.'), new JudgedFinding('c', Mark::Defer, null, 'Later.')]);

        $judged = JudgedFindings::of($judgement, [$this->finding('a', Severity::P1), $this->finding('b', Severity::P3), $this->finding('c', Severity::P3)]);

        self::assertSame([], iterator_to_array($judged->notes));
    }

    public function testARejectedP1WithoutANoteHasNone(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject]), [$this->finding('a', Severity::P1)]);

        self::assertSame([], iterator_to_array($judged->notes));
    }

    public function testAGateGapP3MarkedActIsAFollowUpAndNotOnTheActList(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), [$this->gateGap('a', Severity::P3)]);

        self::assertSame([], $judged->actList);
        self::assertEquals([new FollowUp($this->gateGap('a', Severity::P3))], $judged->followUps);
    }

    public function testAGateGapP3MarkedDeferIsAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Defer]), [$this->gateGap('a', Severity::P3)]);

        self::assertEquals([new FollowUp($this->gateGap('a', Severity::P3))], $judged->followUps);
    }

    public function testAGateGapP3MarkedRejectIsRejectedAndNeitherOnTheActListNorAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject]), [$this->gateGap('a', Severity::P3)]);

        self::assertSame([], $judged->actList);
        self::assertSame([], $judged->followUps);
        self::assertEquals([$this->gateGap('a', Severity::P3)], $judged->rejected);
    }

    public function testAGateGapP3WithoutAMarkIsNeitherOnTheActListNorAFollowUpNorRejected(): void
    {
        $judged = JudgedFindings::of($this->marks([]), [$this->gateGap('a', Severity::P3)]);

        self::assertSame([], $judged->actList);
        self::assertSame([], $judged->followUps);
        self::assertSame([], $judged->rejected);
    }

    public function testAGateGapP1MarkedActStaysOnTheActListBecauseTheFlagNeverLiftsItsVeto(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), [$this->gateGap('a', Severity::P1)]);

        self::assertEquals([$this->gateGap('a', Severity::P1)], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAGateGapP2MarkedRejectStaysOnTheActListAndKeepsTheJudgesNote(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Reject, null, 'A gate issue.')]), [$this->gateGap('a', Severity::P2)]);

        self::assertEquals([$this->gateGap('a', Severity::P2)], $judged->actList);
        self::assertSame([], $judged->rejected);
        self::assertSame('A gate issue.', $judged->notes->noteOf('a'));
    }

    public function testAfterAnInvalidJudgeAnswerAGateGapP2StaysOnTheActListAndMakesNoFollowUp(): void
    {
        $gap = $this->gateGap('a', Severity::P2);

        $judged = JudgedFindings::of(Judgement::everyBlockingFindingActs([$gap]), [$gap]);

        self::assertEquals([$gap], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAP3MarkedActWithoutTheGateGapMarkStaysOnTheActList(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), [$this->gateGap('g', Severity::P3), $this->finding('a', Severity::P3)]);

        self::assertEquals([$this->finding('a', Severity::P3)], $judged->actList);
    }

    public function testAnOwnersProposalMarkedActIsAFollowUpAndNotOnTheActList(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), [$this->proposal('a', FixableBy::Owner)]);

        self::assertSame([], $judged->actList);
        self::assertEquals([new FollowUp($this->proposal('a', FixableBy::Owner))], $judged->followUps);
    }

    public function testAProposalABuilderCouldFixMarkedActStaysOnTheActList(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), [$this->proposal('a', FixableBy::Builder)]);

        self::assertEquals([$this->proposal('a', FixableBy::Builder)], $judged->actList);
    }

    /**
     * @param array<string, Mark> $marks
     */
    private function marks(array $marks): Judgement
    {
        $judged = [];

        foreach ($marks as $id => $mark) {
            $judged[] = new JudgedFinding($id, $mark);
        }

        return new Judgement($judged);
    }

    /**
     * @return list<Finding>
     */
    private function findings(string ...$ids): array
    {
        return array_map(fn (string $id): Finding => $this->finding($id, Severity::P3), array_values($ids));
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null);
    }

    private function gateGap(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'The gate misses this case.', FixableBy::Builder, false, 'cs', null, gate_gap: true);
    }

    private function proposal(string $id, FixableBy $fixableBy): Finding
    {
        return new Finding($id, Severity::P3, 'docs/user/README.md', 1, null, 'Split the long chapter.', $fixableBy, false, 'Proposal: user guide structure', null);
    }
}
