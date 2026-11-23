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
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;

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

    public function testAFailedMessageWhoseBlockTheLedgerRefusesIsLoggedWithoutStoppingTheWorker(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        ($this->fixture->failedMessages(new InMemoryLedger(accepted: 0)))(new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('gave up')));

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame(RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $this->fixture->log->cleanLogs();
        [[$level, , $context]] = $logs;
        self::assertSame(['error', $message->reviewerId], [$level, $context['reviewer']]);
    }

    // ReviewerNotFound is not recoverable, so a message whose row is gone reaches the failure path after its retries; the listener must not throw it out, or the worker stops before parking the message.
    public function testAFailedMessageForAReviewerThatIsGoneIsLoggedWithoutStoppingTheWorker(): void
    {
        $this->fixture->start(1);
        $message = new ReviewWithReviewer(Uuid::v7()->toRfc4122());

        ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('gave up')));

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($this->fixture->bus->messages[0]->reviewerId)->state());
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $this->fixture->log->cleanLogs();
        [[$level, , $context]] = $logs;
        self::assertSame(['error', $message->reviewerId], [$level, $context['reviewer']]);
        self::assertInstanceOf(ReviewerNotFound::class, $context['exception']);
    }

    public function testARealWorkerParksAFailedMessageWhoseReviewerIsGoneAndKeepsRunning(): void
    {
        $this->fixture->start(1);
        $incoming = new InMemoryTransport();
        $incoming->send(new Envelope(new ReviewWithReviewer(Uuid::v7()->toRfc4122())));
        $failed = new InMemoryTransport();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $this->fixture->failedMessages());
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['async' => static fn (): InMemoryTransport => $failed])));

        new Worker(['async' => $incoming], new FailingBus(), $dispatcher, clock: $this->fixture->clock)->run(['sleep' => 0, 'stop_when_empty' => true]);

        self::assertCount(1, $failed->getSent());
        self::assertCount(1, $incoming->getRejected());
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $this->fixture->log->cleanLogs();
        self::assertSame([['error', 'The reviewer of a failed message was not blocked; its message stays in the failure transport.']], array_map(static fn (array $log): array => [$log[0], $log[1]], $logs));
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

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }
}
