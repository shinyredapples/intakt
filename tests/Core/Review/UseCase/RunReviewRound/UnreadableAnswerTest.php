<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// Issue #1823: an answer Intakt cannot read is asked for once more and then fails the reviewer, so it never ends a round as changes.
#[Group('persistence')]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(VerdictParser::class)]
#[CoversClass(VerdictUnreadable::class)]
#[CoversClass(FailureInWords::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(AccountLimitedVendor::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ChangeDecision::class)]
final class UnreadableAnswerTest extends KernelTestCase
{
    private const string FAILED = 'role-1 reviewer failed, the reviewer\'s answer could not be read as a verdict';

    private ScriptedRound $run;

    public function testOneUnreadableAnswerThenAReadableOneGivesTheVerdict(): void
    {
        $review = $this->reviewedBy(1, 1, 'approve');

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame([], [...$review->reasons]);
    }

    public function testTheUnreadableAnswerAndItsRetryAreEachSettledOnce(): void
    {
        $this->reviewedBy(1, 1, 'approve');

        self::assertSame([['started', 'settled'], ['started', 'settled']], array_values($this->run->usagePhasesByCall()));
    }

    public function testTwoUnreadableAnswersEndAPanelOfOneBlockedWithTheReasonAndNoVerdict(): void
    {
        $review = $this->reviewedBy(1, 2, 'approve');

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([self::FAILED], [...$review->reasons]);
        self::assertSame([], $review->verdicts);
        self::assertSame([], $review->actList);
    }

    public function testTwoUnreadableAnswersRecordTheReviewerAsFailedWithTheUnreadableAnswerAsItsCause(): void
    {
        $this->reviewedBy(1, 2, 'approve');

        self::assertSame([VerdictUnreadable::class], $this->run->payloadValues(LedgerEventKind::ReviewerFailed, 'failure'));
        self::assertSame([], $this->run->payloadValues(LedgerEventKind::VerdictReceived, 'verdict'));
    }

    public function testAReviewerFailedThatWayBesideAnApprovalBlocksAPanelOfTwoUnderTheAllPolicy(): void
    {
        $review = $this->reviewedBy(2, 2, 'approve');

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([self::FAILED], [...$review->reasons]);
        self::assertCount(1, $review->verdicts);
    }

    public function testAReviewerFailedThatWayBesideOneAskingForChangesGivesTheChangesOfTheOtherReviewer(): void
    {
        $review = $this->reviewedBy(2, 2, 'changes');

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame([self::FAILED], [...$review->reasons]);
        self::assertCount(1, $review->verdicts);
    }

    protected function setUp(): void
    {
        $this->run = new ScriptedRound(new RoundFixture(self::getContainer()), self::getContainer());
    }

    // The reviewers are delivered one after the other, so the unreadable answers all go to the first reviewer and its retry.
    private function reviewedBy(int $reviewers, int $unreadable, string $decision): ChangeReview
    {
        $platform = $this->run->answeringAfterUnreadable($unreadable, $decision);

        return $this->run->review($reviewers, $this->run->vendor($platform, $platform));
    }
}
