<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\ReviewerForLoop\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\ReviewerForLoop\Contract\Agreement;
use Intakt\Core\ReviewerForLoop\Contract\LoopFinding;
use Intakt\Core\ReviewerForLoop\Contract\LoopReview;
use Intakt\Core\ReviewerForLoop\Contract\LoopReviews;
use Intakt\Core\ReviewerForLoop\Contract\PublishedHead;
use Intakt\Core\ReviewerForLoop\Contract\PullRequestComment;
use Intakt\Core\ReviewerForLoop\Contract\ReviewerDecision;
use Intakt\Core\ReviewerForLoop\Contract\ReviewOutcome;
use Intakt\Core\ReviewerForLoop\Contract\WeeklyReport;
use Intakt\Core\ReviewerForLoop\Domain\LoopReviewReader;
use Intakt\Core\ReviewerForLoop\Domain\MergedSpot;
use Intakt\Core\ReviewerForLoop\Domain\PostedComment;
use Intakt\Core\ReviewerForLoop\Domain\PublishedComparison;
use Intakt\Core\ReviewerForLoop\Domain\RecordedPullRequest;
use Intakt\Core\ReviewerForLoop\Domain\ReviewComparison;
use Intakt\Core\ReviewerForLoop\Domain\Week;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishedComparison::class)]
#[CoversClass(RecordedPullRequest::class)]
#[CoversClass(Week::class)]
#[CoversClass(WeeklyReport::class)]
#[UsesClass(PostedComment::class)]
#[UsesClass(PublishedHead::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(ReviewComparison::class)]
#[UsesClass(MergedSpot::class)]
#[UsesClass(PullRequestComment::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
#[UsesClass(ReviewerDecision::class)]
final class PublishedComparisonTest extends TestCase
{
    private const string LOOP = 'loop-bot';
    private const string INTAKT = 'intakt-app[bot]';
    private const string APPROVE = "```json\n{\"verdict\": \"approve\"}\n```";
    private const string CHANGES = "```json\n{\"verdict\": \"changes\"}\n```";
    private const string GPT = "Reviewer: gpt\n";

    public function testBothSidesApprovingTheHeadAgree(): void
    {
        $report = $this->report($this->loop('h1', self::APPROVE), $this->intakt('h1', self::APPROVE));

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
    }

    public function testBothSidesAskingForChangesAgree(): void
    {
        $report = $this->report($this->loop('h1', self::CHANGES), $this->intakt('h1', self::CHANGES));

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
    }

    public function testTheLoopAskingForChangesWhereIntaktApprovesDisagrees(): void
    {
        $report = $this->report($this->loop('h1', self::CHANGES), $this->intakt('h1', self::APPROVE));

        self::assertSame(Agreement::Disagree, $report->heads[0]->agreement);
    }

    public function testOneChangesVoteOnASideMakesThatSideChangesWhateverItsOtherReviewSays(): void
    {
        $report = $this->report($this->loop('h1', self::CHANGES), $this->loop('h1', self::APPROVE), $this->intakt('h1', self::CHANGES));

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
    }

    public function testAnUnreadableReviewBesideAChangesVoteIsIncompleteNeverAgreement(): void
    {
        $report = $this->report($this->loop('h1', self::CHANGES), $this->loop('h1', 'no verdict'), $this->intakt('h1', self::CHANGES));

        self::assertSame(Agreement::Incomplete, $report->heads[0]->agreement);
    }

    public function testAnUnreadableLoopReviewIsIncompleteNeverAgreement(): void
    {
        $report = $this->report($this->loop('h1', 'no verdict'), $this->intakt('h1', self::APPROVE));

        self::assertSame(Agreement::Incomplete, $report->heads[0]->agreement);
        self::assertEquals([new ReviewerDecision('gpt', ReviewOutcome::Unreadable)], $report->heads[0]->loopDecisions);
    }

    public function testAHeadIntaktPublishedNoReviewOnIsIncomplete(): void
    {
        $report = $this->report($this->loop('h1', self::APPROVE));

        self::assertSame(Agreement::Incomplete, $report->heads[0]->agreement);
        self::assertSame([], $report->heads[0]->intaktDecisions);
    }

    public function testIntaktsDecisionIsNamedIntaktAndTheLoopsByItsReviewerLine(): void
    {
        $report = $this->report($this->loop('h1', self::APPROVE), $this->intakt('h1', self::CHANGES));

        self::assertEquals([new ReviewerDecision('gpt', ReviewOutcome::Approve)], $report->heads[0]->loopDecisions);
        self::assertEquals([new ReviewerDecision('intakt', ReviewOutcome::Changes)], $report->heads[0]->intaktDecisions);
    }

    public function testFindingsWithinTheLineWindowMatchAndTheRestAreOneSided(): void
    {
        $report = $this->report(
            $this->loop('h1', "[P2] src/A.php:10 guard\n[P1] src/B.php:5 lock\n".self::CHANGES),
            $this->intakt('h1', '[P2] src/A.php:'.(10 + ReviewComparison::LINE_WINDOW).": guard\n[P3] src/C.php:1: name\n".self::CHANGES),
        );

        $head = $report->heads[0];
        self::assertSame(1, $head->matched);
        self::assertEquals([new LoopFinding('src/B.php', 5, Severity::P1)], $head->loopOnly);
        self::assertEquals([new LoopFinding('src/C.php', 1, Severity::P3)], $head->intaktOnly);
    }

    public function testIntaktsFollowUpFindingsUnderAnApprovalAreStillFindings(): void
    {
        $report = $this->report($this->loop('h1', self::APPROVE), $this->intakt('h1', "Follow-ups:\n[P2] src/A.php:3: later\n".self::APPROVE));

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
        self::assertEquals([new LoopFinding('src/A.php', 3, Severity::P2)], $report->heads[0]->intaktOnly);
    }

    public function testEachHeadOfAPullRequestIsComparedOnItsOwn(): void
    {
        $report = $this->report($this->loop('h1', self::APPROVE), $this->intakt('h1', self::APPROVE), $this->loop('h2', self::CHANGES), $this->intakt('h2', self::APPROVE));

        self::assertSame(['h1', 'h2'], array_map(static fn (PublishedHead $head): string => $head->head, $report->heads));
        self::assertSame(1, $report->withAgreement(Agreement::Agree));
        self::assertSame(1, $report->withAgreement(Agreement::Disagree));
    }

    public function testAHeadWhoseLastReviewIsAtTheWeeksStartIsInTheWeek(): void
    {
        $report = $this->report(new PostedComment(new PullRequestComment(self::LOOP, 'h1', self::GPT.self::APPROVE), $this->moment('2026-11-02T00:00:00Z')));

        self::assertCount(1, $report->heads);
    }

    public function testAHeadWhoseLastReviewIsAtTheWeeksEndBelongsToTheNextWeek(): void
    {
        $report = $this->report(new PostedComment(new PullRequestComment(self::LOOP, 'h1', self::GPT.self::APPROVE), $this->moment('2026-11-09T00:00:00Z')));

        self::assertSame([], $report->heads);
    }

    public function testAHeadIsPlacedByItsLastReviewSoAReviewInTheWeekBringsAnEarlierOneAlong(): void
    {
        $report = $this->report(
            new PostedComment(new PullRequestComment(self::LOOP, 'h1', self::GPT.self::CHANGES), $this->moment('2026-11-01T23:00:00Z')),
            new PostedComment(new PullRequestComment(self::INTAKT, 'h1', self::CHANGES), $this->moment('2026-11-02T01:00:00Z')),
        );

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
    }

    public function testAHeadReviewedOnlyAfterTheWeekIsLeftOut(): void
    {
        $report = $this->report(
            new PostedComment(new PullRequestComment(self::LOOP, 'h1', self::GPT.self::CHANGES), $this->moment('2026-11-08T23:00:00Z')),
            new PostedComment(new PullRequestComment(self::INTAKT, 'h1', self::CHANGES), $this->moment('2026-11-09T01:00:00Z')),
        );

        self::assertSame([], $report->heads);
    }

    public function testACommentBySomeoneElseNeitherReviewsNorPlacesAHead(): void
    {
        $report = $this->report(new PostedComment(new PullRequestComment('alice', 'h1', self::APPROVE), $this->moment('2026-11-03T00:00:00Z')));

        self::assertSame([], $report->heads);
    }

    public function testOnlyTheLoopsGptReviewIsComparedWithIntakts(): void
    {
        $report = $this->report(
            $this->loop('h1', self::APPROVE),
            new PostedComment(new PullRequestComment(self::LOOP, 'h1', "Reviewer: claude\n".self::CHANGES), $this->moment('2026-11-04T00:00:00Z')),
            $this->intakt('h1', self::APPROVE),
        );

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
        self::assertEquals([new ReviewerDecision('gpt', ReviewOutcome::Approve)], $report->heads[0]->loopDecisions);
    }

    public function testAHeadOnlyAnotherLoopReviewerReviewedIsLeftOut(): void
    {
        $report = $this->report(new PostedComment(new PullRequestComment(self::LOOP, 'h1', "Reviewer: claude\n".self::APPROVE), $this->moment('2026-11-04T00:00:00Z')));

        self::assertSame([], $report->heads);
    }

    public function testAnOrdinaryLoopMessageAfterTheWeekDoesNotMoveAReviewedHeadOutOfIt(): void
    {
        $report = $this->report(
            $this->loop('h1', self::APPROVE),
            $this->intakt('h1', self::APPROVE),
            new PostedComment(new PullRequestComment(self::LOOP, 'h1', 'Merged this pull request.'), $this->moment('2026-11-10T00:00:00Z')),
        );

        self::assertCount(1, $report->heads);
    }

    public function testAnIntaktMessageWithoutAVerdictNeitherReviewsNorPlacesAHead(): void
    {
        $report = $this->report(
            $this->loop('h1', self::APPROVE),
            $this->intakt('h1', self::APPROVE),
            new PostedComment(new PullRequestComment(self::INTAKT, 'h1', 'Started a review round.'), $this->moment('2026-11-10T00:00:00Z')),
        );

        self::assertSame(Agreement::Agree, $report->heads[0]->agreement);
    }

    public function testAHeadOfDigitsOnlyStaysText(): void
    {
        $report = $this->report($this->loop('1234567', self::APPROVE));

        self::assertSame('1234567', $report->heads[0]->head);
    }

    public function testTheReportAddsTheHeadsUpAndKeepsItsWeek(): void
    {
        $report = $this->report(
            $this->loop('h1', "[P1] src/A.php:1 x\n".self::CHANGES),
            $this->intakt('h1', "[P1] src/A.php:2: x\n[P2] src/B.php:2: y\n".self::CHANGES),
            $this->loop('h2', "[P2] src/C.php:1 z\n".self::CHANGES),
        );

        self::assertSame([1, 1, 2, 1, 1], [$report->withAgreement(Agreement::Agree), $report->withAgreement(Agreement::Incomplete), count($report->heads), $report->matched(), $report->loopOnly()]);
        self::assertSame(1, $report->intaktOnly());
        self::assertEquals([$this->moment('2026-11-02T00:00:00Z'), $this->moment('2026-11-09T00:00:00Z')], [$report->from, $report->until]);
    }

    private function loop(string $head, string $body): PostedComment
    {
        return new PostedComment(new PullRequestComment(self::LOOP, $head, self::GPT.$body), $this->moment('2026-11-04T10:00:00Z'));
    }

    private function intakt(string $head, string $body): PostedComment
    {
        return new PostedComment(new PullRequestComment(self::INTAKT, $head, $body), $this->moment('2026-11-04T10:05:00Z'));
    }

    private function report(PostedComment ...$comments): WeeklyReport
    {
        $pullRequest = new RecordedPullRequest(7, self::LOOP, self::INTAKT, array_values($comments));

        return new PublishedComparison(new LoopReviewReader(), new ReviewComparison())->report([$pullRequest], Week::endingAt($this->moment('2026-11-09T00:00:00Z')));
    }

    private function moment(string $text): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sp', $text, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $moment);

        return $moment;
    }
}
