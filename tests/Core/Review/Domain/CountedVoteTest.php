<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CountedVote;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedVote::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class CountedVoteTest extends TestCase
{
    public function testAChangesVerdictWhoseFindingsAreAllP3AndOffTheActListCountsAsApprovalWithFollowUps(): void
    {
        $verdict = $this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Two.'));

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P3, 'Two.')], [])->of($verdict);

        self::assertSame(Decision::Approve, $vote->verdict);
        self::assertSame([], $vote->findings);
        self::assertSame(['A', 'B'], $vote->follow_ups);
    }

    public function testAChangesVerdictWithOneP3OnTheActListStillCountsAsChanges(): void
    {
        $verdict = $this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P3, 'Two.'));

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P3, 'Two.')], ['F2'])->of($verdict);

        self::assertSame($verdict, $vote);
    }

    public function testAChangesVerdictWithAP2CountsAsChanges(): void
    {
        $verdict = $this->changes($this->finding('A', Severity::P3, 'One.'), $this->finding('B', Severity::P2, 'Two.'));

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'One.'), $this->finding('F2', Severity::P2, 'Two.')], [])->of($verdict);

        self::assertSame($verdict, $vote);
    }

    public function testARepeatedP3IsFoundUnderItsLedgerIdThoughAnotherReviewersTextWasKept(): void
    {
        $verdict = $this->changes(new Finding('F4', Severity::P3, null, null, null, 'My words.', FixableBy::Builder, true, null, null));

        $vote = new CountedVote([$this->finding('F4', Severity::P3, 'Other words.')], ['F4'])->of($verdict);

        self::assertSame($verdict, $vote);
    }

    public function testANewP3MatchingTwoReportedFindingsCountsAsChangesWhenEitherIsOnTheActList(): void
    {
        $verdict = $this->changes($this->finding('A', Severity::P3, 'Same.'));

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'Same.'), $this->finding('F2', Severity::P3, 'Same.')], ['F2'])->of($verdict);

        self::assertSame($verdict, $vote);
    }

    public function testAP3NoReportedFindingMatchesKeepsTheVoteAsChanges(): void
    {
        $verdict = $this->changes($this->finding('A', Severity::P3, 'Mine.'));

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'Another.')], [])->of($verdict);

        self::assertSame($verdict, $vote);
    }

    public function testAFailedReviewIsNeverCountedAsApproval(): void
    {
        $failed = new Verdict(Decision::Changes, [$this->finding(Verdict::NO_TOOLS, Severity::P3, 'No tools.')], [], []);

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'No tools.')], [])->of($failed);

        self::assertSame($failed, $vote);
    }

    public function testABlockedVerdictWithOnlyP3FindingsStaysBlocked(): void
    {
        $blocked = new Verdict(Decision::Blocked, [$this->finding('A', Severity::P3, 'One.')], [], []);

        $vote = new CountedVote([$this->finding('F1', Severity::P3, 'One.')], [])->of($blocked);

        self::assertSame($blocked, $vote);
    }

    public function testAnApprovalIsCountedAsItIs(): void
    {
        $approval = new Verdict(Decision::Approve, [], [], []);

        self::assertSame($approval, new CountedVote([], [])->of($approval));
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }

    private function finding(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 7, null, $text, FixableBy::Builder, false, null, null);
    }
}
