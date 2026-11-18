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
use Intakt\Core\Review\Contract\Mark;
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

    public function testAP1RejectedWithAReasonKeepsTheJudgesNoteAsTheJudgeGaveIt(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Reject, null, '  Not a bug here.  ')]), [$this->finding('a', Severity::P1)]);

        self::assertSame(['a' => '  Not a bug here.  '], $judged->judgeNotes);
        self::assertCount(1, $judged->actList);
    }

    public function testAP2DeferredWithAReasonKeepsTheJudgesNote(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Defer, null, 'Needs its own design.')]), [$this->finding('a', Severity::P2)]);

        self::assertSame(['a' => 'Needs its own design.'], $judged->judgeNotes);
    }

    public function testAP1WithoutAJudgeAnswerHasNoNote(): void
    {
        self::assertSame([], JudgedFindings::of($this->marks([]), [$this->finding('a', Severity::P1)])->judgeNotes);
    }

    public function testAP1ActedOrRejectedWithABlankReasonHasNoNote(): void
    {
        $judgement = new Judgement([new JudgedFinding('a', Mark::Act, null, 'Real.'), new JudgedFinding('b', Mark::Reject, null, '  ')]);

        self::assertSame([], JudgedFindings::of($judgement, [$this->finding('a', Severity::P1), $this->finding('b', Severity::P1)])->judgeNotes);
    }

    public function testAP3RejectedWithAReasonCarriesNoNote(): void
    {
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('a', Mark::Reject, null, 'Taste.')]), [$this->finding('a', Severity::P3)]);

        self::assertSame([], $judged->judgeNotes);
        self::assertSame([], $judged->actList);
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
}
