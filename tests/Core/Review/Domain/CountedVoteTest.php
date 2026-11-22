<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CountedVote;
use Intakt\Core\Review\Domain\FindingLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedVote::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(JudgedFindings::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(FollowUp::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(FindingLedger::class)]
final class CountedVoteTest extends TestCase
{
    public function testAChangesVerdictWhoseP3FindingsTheJudgeDeferredOrRejectedCountsAsApproval(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P3, 'Two.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Reject])->of($this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Two.')));

        self::assertSame(Decision::Approve, $vote);
    }

    public function testAChangesVerdictWithOneP3OnTheActListCountsAsChanges(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P3, 'Two.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Act])->of($this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Two.')));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testAChangesVerdictWithAJudgedP2CountsAsChanges(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P2, 'Two.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Defer])->of($this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P2, 'Two.')));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testAChangesVerdictWhoseOnlyP2TheQuoteCheckDroppedCountsAsBlocked(): void
    {
        $p2 = $this->finding('F1', Severity::P2, 'Call `ghost`.');

        $vote = $this->counted([$p2], [], [$p2])->of($this->changes($this->finding('A', Severity::P2, 'Call `ghost`.')));

        self::assertSame(Decision::Blocked, $vote);
    }

    public function testAChangesVerdictWithAP3TheQuoteCheckDroppedCountsAsBlocked(): void
    {
        $dropped = $this->finding('F2', Severity::P3, 'Call `ghost`.');
        $reported = [$this->finding('F1', Severity::P3, 'One.'), $dropped];

        $vote = $this->counted($reported, ['F1' => Mark::Defer], [$dropped])->of($this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Call `ghost`.')));

        self::assertSame(Decision::Blocked, $vote);
    }

    public function testADroppedFindingBesideOneOnTheActListStillCountsAsChanges(): void
    {
        $dropped = $this->finding('F2', Severity::P2, 'Call `ghost`.');
        $reported = [$this->finding('F1', Severity::P2, 'One.'), $dropped];

        $vote = $this->counted($reported, ['F1' => Mark::Act], [$dropped])->of($this->changes($this->finding('A', Severity::P2, 'One.'), $this->finding('B', Severity::P2, 'Call `ghost`.')));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testARepeatOfAShownIdIsFoundUnderThatIdThoughAnotherReviewersTextWasKept(): void
    {
        $repeat = new Finding('F4', Severity::P3, null, null, null, 'My words.', FixableBy::Builder, true, null, null);

        $vote = $this->countedShowing(['F4'], [$this->finding('F4', Severity::P3, 'Other words.')], ['F4' => Mark::Act])->of($this->changes($repeat));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testADeferredRepeatCountsAsApprovalThoughAFindingOfTheSameTextIsOnTheActList(): void
    {
        $repeat = new Finding('F1', Severity::P3, null, null, null, 'Rename the helper.', FixableBy::Builder, true, null, null);
        $another = new Finding('F2', Severity::P3, null, null, null, 'Rename the helper.', FixableBy::Builder, false, null, null);

        $vote = $this->countedShowing(['F1'], [$repeat, $another], ['F1' => Mark::Defer, 'F2' => Mark::Act])->of($this->changes($repeat));

        self::assertSame(Decision::Approve, $vote);
    }

    public function testARepeatNamingAnIdNotShownIsMatchedByItsPlaceAndText(): void
    {
        $guessed = new Finding('F1', Severity::P3, 'src/A.php', 7, null, 'One.', FixableBy::Builder, true, null, null);
        $reported = [$this->finding('F1', Severity::P3, 'Other.'), $this->finding('F2', Severity::P3, 'One.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Act])->of($this->changes($guessed));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testANewP3MatchingTwoReportedFindingsCountsAsChangesWhenEitherIsOnTheActList(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'Same.'), $this->finding('F2', Severity::P3, 'Same.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Act])->of($this->changes($this->finding('A', Severity::P3, 'Same.')));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testAP3NoReportedFindingMatchesCountsAsBlocked(): void
    {
        $vote = $this->counted([$this->finding('F1', Severity::P3, 'Another.')], ['F1' => Mark::Defer])->of($this->changes($this->finding('A', Severity::P3, 'Mine.')));

        self::assertSame(Decision::Blocked, $vote);
    }

    public function testAChangesVerdictWithoutFindingsIsNeverCountedAsApproval(): void
    {
        self::assertSame(Decision::Changes, $this->counted([], [])->of($this->changes()));
    }

    public function testAFailedReviewIsNeverCountedAsApproval(): void
    {
        $failed = new Verdict(Decision::Changes, [$this->finding(Verdict::NO_TOOLS, Severity::P3, 'No tools.')], [], []);

        self::assertSame(Decision::Blocked, $this->counted([$this->finding('F1', Severity::P3, 'No tools.')], ['F1' => Mark::Defer])->of($failed));
    }

    public function testAChangesVerdictNamingAReservedFailureIdBesideAP2CountsAsBlocked(): void
    {
        $failed = $this->changes($this->finding(Verdict::NO_TOOLS, Severity::P2, 'The review could not run.'), $this->finding('A', Severity::P2, 'One.'));

        self::assertSame(Decision::Blocked, $this->counted([], [])->of($failed));
    }

    public function testAnApprovalNamingAReservedFailureIdKeepsItsDecision(): void
    {
        $failed = new Verdict(Decision::Approve, [$this->finding(Verdict::NO_TOOLS, Severity::P3, 'No tools.')], [], []);

        self::assertSame(Decision::Approve, $this->counted([], [])->of($failed));
    }

    public function testABlockedFailedReviewStaysBlocked(): void
    {
        self::assertSame(Decision::Blocked, $this->counted([], [])->of(Verdict::noTools()));
    }

    public function testAP3TheJudgeGaveNoMarkNeverCountsAsApproval(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'One.')];

        $vote = $this->counted($reported, [])->of($this->changes($this->finding('A', Severity::P3, 'One.')));

        self::assertSame(Decision::Blocked, $vote);
    }

    public function testAP3TheJudgeGaveNoMarkBesideADeferredOneCountsAsBlocked(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P3, 'Two.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer])->of($this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Two.')));

        self::assertSame(Decision::Blocked, $vote);
    }

    public function testADeferredP3CountsAsApprovalThoughAnotherReviewersP2OfTheSameTextIsOnTheActList(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'Same.'), $this->finding('F2', Severity::P2, 'Same.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Act])->of($this->changes($this->finding('A', Severity::P3, 'Same.')));

        self::assertSame(Decision::Approve, $vote);
    }

    public function testAP2CountsAsChangesThoughAnotherReviewersP3OfTheSameTextWasDeferred(): void
    {
        $reported = [$this->finding('F1', Severity::P3, 'Same.'), $this->finding('F2', Severity::P2, 'Same.')];

        $vote = $this->counted($reported, ['F1' => Mark::Defer, 'F2' => Mark::Defer])->of($this->changes($this->finding('A', Severity::P2, 'Same.')));

        self::assertSame(Decision::Changes, $vote);
    }

    public function testADeferredP3CountsAsApprovalThoughAnotherReviewersP3OfTheSameTextCitingAnotherRequirementIsOnTheActList(): void
    {
        $deferred = new Finding('A', Severity::P3, 'src/A.php', 5, null, 'Rename the helper.', FixableBy::Builder, false, 'Local cleanup', null);
        $acted = new Finding('B', Severity::P3, 'src/A.php', 5, null, 'Rename the helper.', FixableBy::Builder, false, 'Required naming rule', null);
        $vote = $this->counted([$deferred->withId('F1'), $acted->withId('F2')], ['F1' => Mark::Defer, 'F2' => Mark::Act]);

        self::assertSame(Decision::Approve, $vote->of($this->changes($deferred)));
        self::assertSame(Decision::Changes, $vote->of($this->changes($acted)));
    }

    public function testADeferredP3CountsAsApprovalThoughAnotherReviewersP3DifferingOnlyInEvidenceIsOnTheActList(): void
    {
        $deferred = new Finding('a', Severity::P3, 'src/A.php', 5, null, 'Handle an empty result.', FixableBy::Builder, false, null, null);
        $acted = new Finding('b', Severity::P3, 'src/A.php', 5, null, 'Handle an empty result.', FixableBy::Builder, false, null, new Evidence('EmptyResultTest.php', 'Expected [] but got null'));

        [$first, $second, $vote] = $this->countedFromLedger($deferred, $acted);

        self::assertSame(Decision::Approve, $vote->of($first));
        self::assertSame(Decision::Changes, $vote->of($second));
    }

    public function testADeferredP3CountsAsApprovalThoughAnotherReviewersP3DifferingOnlyInRegressionIsOnTheActList(): void
    {
        $deferred = new Finding('a', Severity::P3, 'src/A.php', 5, null, 'Handle an empty result.', FixableBy::Builder, false, null, null);
        $acted = new Finding('b', Severity::P3, 'src/A.php', 5, null, 'Handle an empty result.', FixableBy::Builder, false, null, null, true);

        [$first, $second, $vote] = $this->countedFromLedger($deferred, $acted);

        self::assertSame(Decision::Approve, $vote->of($first));
        self::assertSame(Decision::Changes, $vote->of($second));
    }

    public function testABlockedVerdictWithOnlyP3FindingsStaysBlocked(): void
    {
        $blocked = new Verdict(Decision::Blocked, [$this->finding('A', Severity::P3, 'One.')], [], []);

        self::assertSame(Decision::Blocked, $this->counted([$this->finding('F1', Severity::P3, 'One.')], ['F1' => Mark::Defer])->of($blocked));
    }

    public function testAnApprovalIsCountedAsItIs(): void
    {
        self::assertSame(Decision::Approve, $this->counted([], [])->of(new Verdict(Decision::Approve, [], [], [])));
    }

    /**
     * @param list<string>        $shown
     * @param list<Finding>       $reported
     * @param array<string, Mark> $marks
     */
    private function countedShowing(array $shown, array $reported, array $marks): CountedVote
    {
        return new CountedVote($reported, $shown, $this->judged($reported, $marks, []));
    }

    // Through a real ledger, so the reported findings carry every field the ledger keeps and the ids it gives.
    /**
     * @return array{Verdict, Verdict, CountedVote}
     */
    private function countedFromLedger(Finding $deferred, Finding $acted): array
    {
        $first = $this->changes($deferred);
        $second = $this->changes($acted);
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, $first);
        $ledger->recordVerdict(1, $second);
        $reported = $ledger->openReportedIn(1);
        $judged = JudgedFindings::of(new Judgement([new JudgedFinding('F1', Mark::Defer), new JudgedFinding('F2', Mark::Act)]), $reported);

        return [$first, $second, new CountedVote($reported, [], $judged)];
    }

    private function finding(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 7, null, $text, FixableBy::Builder, false, null, null);
    }

    /**
     * @param list<Finding>       $reported
     * @param array<string, Mark> $marks
     * @param list<Finding>       $dropped
     */
    private function counted(array $reported, array $marks, array $dropped = []): CountedVote
    {
        return new CountedVote($reported, [], $this->judged($reported, $marks, $dropped));
    }

    /**
     * @param list<Finding>       $reported
     * @param array<string, Mark> $marks
     * @param list<Finding>       $dropped
     */
    private function judged(array $reported, array $marks, array $dropped): JudgedFindings
    {
        $kept = array_values(array_filter($reported, static fn (Finding $finding): bool => !in_array($finding, $dropped, true)));
        $judgement = new Judgement(array_map(static fn (string $id, Mark $mark): JudgedFinding => new JudgedFinding($id, $mark), array_keys($marks), $marks));

        return JudgedFindings::of($judgement, $kept, array_map(static fn (Finding $finding): DroppedFinding => DroppedFinding::of($finding, 'none of its 1 quoted code fragments is in the change'), $dropped));
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }
}
