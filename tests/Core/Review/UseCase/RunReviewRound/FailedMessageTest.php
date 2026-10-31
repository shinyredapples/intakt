<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[Group('persistence')]
#[CoversClass(BlockReviewerOfFailedMessage::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
final class FailedMessageTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAReviewerMessageThatReachesTheFailureTransportMarksTheReviewerBlockedAndCompletesTheRound(): void
    {
        $round = $this->fixture->start(2);
        $this->fixture->deliver($this->fixture->bus->messages[0], new ScriptedVendor());
        $message = $this->fixture->bus->messages[1];

        ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('gave up')));

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame([ReviewerState::Verdict, ReviewerState::Blocked], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $stored->reviewers()));
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertContains(LedgerEventKind::ReviewerBlocked, $this->fixture->ledgerKinds());
    }

    public function testAFailedMessageThatWillStillBeRetriedLeavesTheReviewerAsItWas(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('again'));
        $event->setForRetry();

        ($this->fixture->failedMessages())($event);

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame(RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAFailedMessageForAReviewerThatAlreadyFinishedChangesNothing(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $this->fixture->deliver($message, new ScriptedVendor());

        ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('late')));

        self::assertSame(ReviewerState::Verdict, $this->fixture->reviewer($message->reviewerId)->state());
    }

    public function testAFailedMessageOfAnotherKindIsLeftAlone(): void
    {
        $this->fixture->start(1);

        ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('other')));

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($this->fixture->bus->messages[0]->reviewerId)->state());
    }
}
