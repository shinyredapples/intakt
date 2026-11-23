<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// A killed worker leaves its reviewer running; the test stops a worker right after it took the reviewer and moves the clock past the hold, so the order is forced and nothing repeats.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
final class KilledWorkerTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testAReviewerWhoseWorkerStoppedIsStartedOnceMoreWhenItsMessageReturnsAfterTheHold(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $this->fixture->takeAndStop($message);
        $this->fixture->clock->modify('+1 hour');
        $vendor = new ScriptedVendor();

        $this->fixture->deliver($message, $vendor);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(1, $vendor->calls);
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerFindingsRecorded], $this->fixture->ledgerKinds());
    }

    public function testAReviewerStartedOnceMoreReviewsInANewConversation(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $stopped = $this->fixture->takeAndStop($message);
        $this->fixture->clock->modify('+1 hour');
        $vendor = new ScriptedVendor();

        $this->fixture->deliver($message, $vendor);

        self::assertCount(1, $vendor->conversations);
        self::assertNotSame($stopped->conversationId()->toRfc4122(), $vendor->conversations[0]);
    }

    public function testAReviewerWhoseWorkerStoppedTwiceCountsAsBlockedAndIsNotReviewed(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $this->fixture->takeAndStop($message);
        $this->fixture->clock->modify('+1 hour');
        $this->fixture->takeAndStop($message);
        $this->fixture->clock->modify('+1 hour');
        $vendor = new ScriptedVendor();

        $this->fixture->deliver($message, $vendor);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(0, $vendor->calls);
        self::assertSame(ReviewerState::Blocked, $stored->reviewers()[0]->state());
        self::assertSame('the worker stopped twice before the reviewer had a verdict', $stored->reviewers()[0]->failure());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerBlocked], $this->fixture->ledgerKinds());
    }

    public function testARoundWhoseLastOpenReviewerIsBlockedIsComplete(): void
    {
        $round = $this->fixture->start(2);
        $this->fixture->deliver($this->fixture->bus->messages[0], new ScriptedVendor());
        self::assertSame(RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
        $stopped = $this->fixture->bus->messages[1];
        $this->fixture->takeAndStop($stopped);
        $this->fixture->clock->modify('+1 hour');
        $this->fixture->takeAndStop($stopped);
        $this->fixture->clock->modify('+1 hour');

        $this->fixture->deliver($stopped, new ScriptedVendor());

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame([ReviewerState::Verdict, ReviewerState::Blocked], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $stored->reviewers()));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }
}
