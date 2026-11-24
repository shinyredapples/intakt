<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Core\Review\UseCase\RunReviewRound\ResultNotStored;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerStillHeld;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewEventNotRecorded;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundOpening;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

#[Group('persistence')]
#[CoversClass(RunReviewRoundHandler::class)]
#[CoversClass(RoundOpening::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[CoversClass(ReviewerStillHeld::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ResultNotStored::class)]
#[UsesClass(BlockReviewerOfFailedMessage::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
#[UsesClass(RoundOpened::class)]
final class RunReviewRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testARoundDispatchesOneMessagePerReviewerAndWaitsForThem(): void
    {
        $round = $this->fixture->start(3);

        self::assertCount(3, $this->fixture->bus->messages);
        self::assertSame(RoundState::Collecting, $round->state());
        self::assertSame([ReviewerState::Queued, ReviewerState::Queued, ReviewerState::Queued], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $round->reviewers()));
    }

    public function testARoundWhoseMessagesCannotAllBeQueuedLeavesNoRoundAndNoLedgerEventBehind(): void
    {
        try {
            $this->fixture->startOn(new RefusingQueue(new RecordingBus()), 3);
            self::fail('The refused dispatch was swallowed.');
        } catch (RuntimeException $refused) {
            self::assertSame('queue unavailable', $refused->getMessage());
        }

        self::assertSame([], $this->fixture->storedRounds());
        self::assertSame([], $this->fixture->ledgerKinds());
    }

    public function testAnOpenedRoundIsAnnouncedWithItsProjectOnTheQueueOfItsReviewers(): void
    {
        $round = $this->fixture->start(2);

        self::assertEquals([new RoundOpened($round->id()->toRfc4122(), $round->project())], $this->fixture->bus->announced);
    }

    public function testAnOpenedRoundIsAnnouncedBeforeAnyOfItsReviewerMessages(): void
    {
        $this->fixture->start(3);

        self::assertSame(
            [RoundOpened::class, ReviewWithReviewer::class, ReviewWithReviewer::class, ReviewWithReviewer::class],
            array_map(static fn (object $message): string => $message::class, $this->fixture->bus->dispatched),
        );
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function everyOrderOfThree(): iterable
    {
        yield 'first, second, third' => [[0, 1, 2]];
        yield 'third, second, first' => [[2, 1, 0]];
        yield 'second, third, first' => [[1, 2, 0]];
        yield 'first, third, second' => [[0, 2, 1]];
    }

    /**
     * @param list<int> $order
     */
    #[DataProvider('everyOrderOfThree')]
    public function testARoundCompletesWithTheLastReviewerWhicheverFinishesFirst(array $order): void
    {
        $round = $this->fixture->start(3);
        $vendor = new VerdictPerRole();

        foreach ($order as $position => $index) {
            $this->fixture->deliver($this->fixture->bus->messages[$index], $vendor);

            $isLast = 2 === $position;
            self::assertSame($isLast ? RoundState::Complete : RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
        }

        $reviewers = $this->fixture->round($round->id()->toRfc4122())->reviewers();
        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict, ReviewerState::Verdict], array_map(static fn ($reviewer): ReviewerState => $reviewer->state(), $reviewers));
        self::assertSame(3, $vendor->calls);
    }

    public function testAReviewerAsksTheVendorWithTheIdOfItsRound(): void
    {
        $round = $this->fixture->start(1);
        $vendor = new VerdictPerRole();

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        self::assertSame([$round->id()->toRfc4122()], $vendor->rounds);
    }

    public function testAMessageDeliveredAgainAfterTheRoundIsCompleteChangesNothing(): void
    {
        $round = $this->fixture->start(1);
        $vendor = new VerdictPerRole();
        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
        self::assertSame(1, $vendor->calls);
        self::assertCount(4, $this->fixture->ledgerKinds());
    }

    public function testAFailedReviewerCountsAsFinishedAndTheRoundStillCompletes(): void
    {
        $round = $this->fixture->start(2);
        $failing = new VerdictPerRole(failure: new ProviderFailedTransiently(new RuntimeException('secret detail')));

        $this->fixture->deliver($this->fixture->bus->messages[0], $failing);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole());

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame(ProviderFailedTransiently::class, $stored->reviewers()[0]->failure());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[1]->state());
    }

    public function testAReviewerWhoseStreamWasCutMidAnswerIsStoredAsABlockedVerdictWithTheCutAsItsReasonAndNeverApproves(): void
    {
        $round = $this->fixture->start(1);
        $cut = new VerdictPerRole(otherwise: Verdict::incompleteStream('The stream ended before its terminal event.', new Evidence('the call to anthropic, model big', 'cut by: platform')));

        $this->fixture->deliver($this->fixture->bus->messages[0], $cut);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame(RoundState::Complete, $stored->state());
        /** @var array{verdict: string, findings: list<array{id: string, text: string}>} $verdict */
        $verdict = json_decode((string) $stored->reviewers()[0]->verdictJson(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('blocked', $verdict['verdict']);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict['findings'][0]['id']);
        self::assertStringContainsString('The stream ended before its terminal event.', $verdict['findings'][0]['text']);
    }

    public function testAReviewerAnsweringBlockedWithFindingsIsStoredAsChanges(): void
    {
        $round = $this->fixture->start(1);
        $blocked = new VerdictPerRole(otherwise: new Verdict(Decision::Blocked, [new Finding(
            id: 'F1',
            severity: Severity::P1,
            file: 'src/Cart.php',
            line: 12,
            symbol: null,
            text: 'The total adds the discount.',
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: 'Storytelling',
            evidence: null,
        )], [], []));

        $this->fixture->deliver($this->fixture->bus->messages[0], $blocked);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        /** @var array{verdict: string, findings: list<array{id: string}>} $verdict */
        $verdict = json_decode((string) $stored->reviewers()[0]->verdictJson(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('changes', $verdict['verdict']);
        self::assertSame('F1', $verdict['findings'][0]['id']);
    }

    public function testAVendorFailureOutsideTheContractIsRecordedAsThatFailureAndTheRoundStillCompletes(): void
    {
        $round = $this->fixture->start(1);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(failure: new RuntimeException('401 invalid api key')));

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame(RuntimeException::class, $stored->reviewers()[0]->failure());
        self::assertSame(RoundState::Complete, $stored->state());
    }

    public function testTheStartOfARoundNamesItsChangeItsNumberAndItsQuorumInTheLedger(): void
    {
        $round = $this->fixture->start(2);

        $started = json_decode(iterator_to_array($this->fixture->ledger()->readInOrder())[0]->event->payload, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(['round' => $round->id()->toRfc4122(), 'project' => 'alpha', 'reviewers' => 2, 'change' => RoundFixture::CHANGE, 'number' => 1, 'task' => RoundFixture::TASK, 'quorum' => 'all'], $started);
    }

    public function testTheLedgerHoldsTheStartAndTheEndOfEveryReviewer(): void
    {
        $this->fixture->start(2);
        $failing = new VerdictPerRole(failure: new ProviderFailedTransiently(new RuntimeException('down')));

        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole());
        $this->fixture->deliver($this->fixture->bus->messages[0], $failing);

        self::assertSame(
            [LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerFailed, LedgerEventKind::ReviewerFindingsRecorded],
            $this->fixture->ledgerKinds(),
        );
    }

    public function testAnEndTheLedgerKeepsRefusingIsNotStoredAndTheReviewerIsNotHandedBack(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = new VerdictPerRole();
        $refusingAfterTheStart = new InMemoryLedger(accepted: 1);

        try {
            $this->fixture->worker($this->fixture->entities, $vendor, $refusingAfterTheStart)($message);
            self::fail('The refused ledger event was swallowed.');
        } catch (ResultNotStored $notStored) {
            self::assertInstanceOf(ReviewEventNotRecorded::class, $notStored->getPrevious());
        }

        $reviewer = $this->fixture->reviewer($message->reviewerId);
        self::assertSame(1, $vendor->calls);
        self::assertSame(ReviewerState::Running, $reviewer->state());
        self::assertNull($reviewer->verdictJson());
        self::assertSame(RoundState::Collecting, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAnEndNotStoredIsNotRetriedByMessengerBecauseARetryWouldCallTheReviewerAgain(): void
    {
        $this->fixture->start(1);

        try {
            $this->fixture->worker($this->fixture->entities, new VerdictPerRole(), new InMemoryLedger(accepted: 1))($this->fixture->bus->messages[0]);
            self::fail('The refused ledger event was swallowed.');
        } catch (ResultNotStored $notStored) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $notStored);
        }
    }

    public function testARedeliveryAfterTheStoreAttemptsRanOutDoesNotCallTheAnsweredReviewerAgain(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = new VerdictPerRole();
        $worker = $this->fixture->worker($this->fixture->entities, $vendor, new RefusingVerdictsFirst(new InMemoryLedger(), 3));

        try {
            $worker($message);
        } catch (ResultNotStored) {
        }

        try {
            $worker($message);
            self::fail('The redelivery took the reviewer the first delivery still holds.');
        } catch (ReviewerStillHeld) {
        }

        self::assertSame(1, $vendor->calls);
    }

    public function testTheFailedMessageOfAnEndNotStoredBlocksTheReviewerAndCompletesTheRound(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->worker($this->fixture->entities, new VerdictPerRole(), new RefusingVerdictsFirst(new InMemoryLedger(), 3))($message);
        } catch (ResultNotStored $notStored) {
            ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope($message), 'async', $notStored));
        }

        self::assertSame(ReviewerState::Blocked, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAVerdictTheLedgerRefusesOnceIsStoredOnANewTransactionWithoutCallingTheReviewerAgain(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = new VerdictPerRole();
        $ledger = new InMemoryLedger();
        $refusing = new RefusingVerdictsFirst($ledger, 1);

        $this->fixture->worker($this->fixture->entities, $vendor, $refusing)($message);

        self::assertSame(1, $vendor->calls);
        self::assertSame(ReviewerState::Verdict, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame([1, 1], [$refusing->refused, count(array_filter($ledger->kinds(), static fn (LedgerEventKind $kind): bool => LedgerEventKind::VerdictReceived === $kind))]);
    }

    public function testAVerdictTheLedgerKeepsRefusingIsTriedThreeTimesThenLeftUnstored(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = new VerdictPerRole();
        $ledger = new InMemoryLedger();
        $refusing = new RefusingVerdictsFirst($ledger, 4);

        try {
            $this->fixture->worker($this->fixture->entities, $vendor, $refusing)($message);
            self::fail('The refused ledger event was swallowed.');
        } catch (ResultNotStored) {
        }

        self::assertSame([1, 3], [$vendor->calls, $refusing->refused]);
        self::assertSame(ReviewerState::Running, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertNotContains(LedgerEventKind::VerdictReceived, $ledger->kinds());
    }

    public function testARestartedWorkerSkipsFinishedReviewersAndRunsTheRest(): void
    {
        $round = $this->fixture->start(3);
        $before = new VerdictPerRole();
        $this->fixture->deliver($this->fixture->bus->messages[1], $before);

        // The queue redelivers every message after the restart because it cannot know which ones were handled.
        $after = new VerdictPerRole();
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $after);
        }

        self::assertSame(1, $before->calls);
        self::assertSame(2, $after->calls);
        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testARedeliveryInsideTheHoldOfARunningReviewerComesBackWhenTheHoldEnds(): void
    {
        $this->fixture->start(1);
        $this->leaveRunning($this->fixture->bus->messages[0]);
        $this->fixture->clock->modify('+15 minutes');
        $vendor = new VerdictPerRole();

        try {
            $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);
            self::fail('The held reviewer was taken over.');
        } catch (ReviewerStillHeld $held) {
            self::assertSame(45 * 60 * 1000, $held->getRetryDelay());
        }

        self::assertSame(0, $vendor->calls);
        self::assertSame(ReviewerState::Running, $this->fixture->reviewer($this->fixture->bus->messages[0]->reviewerId)->state());
    }

    public function testAVerdictArrivingAfterTheCeilingWithNoOtherDeliveryFailsTheReviewer(): void
    {
        $round = $this->fixture->start(1);

        $this->fixture->deliver($this->fixture->bus->messages[0], new RedeliveredDuringTheCall(function (): void {
            $this->fixture->clock->modify('+61 minutes');
        }));

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame('the reviewer had no result within the review ceiling', $stored->reviewers()[0]->failure());
        self::assertNull($stored->reviewers()[0]->verdictJson());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerFailed], $this->fixture->ledgerKinds());
    }

    public function testAVerdictArrivingJustInsideTheCeilingIsRecorded(): void
    {
        $round = $this->fixture->start(1);

        $this->fixture->deliver($this->fixture->bus->messages[0], new RedeliveredDuringTheCall(function (): void {
            $this->fixture->clock->modify('+59 minutes');
        }));

        self::assertSame(ReviewerState::Verdict, $this->fixture->round($round->id()->toRfc4122())->reviewers()[0]->state());
    }

    public function testAMessageForAReviewerThatIsNotStoredIsRefused(): void
    {
        $this->fixture->start(1);

        $this->expectException(ReviewerNotFound::class);

        $this->fixture->deliver(new ReviewWithReviewer('019a2600-0000-7000-8000-000000000000'), new VerdictPerRole());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    // Set by hand, because a worker killed between the start and the end of a review leaves exactly this row behind.
    private function leaveRunning(ReviewWithReviewer $message): void
    {
        $reviewer = $this->fixture->reviewer($message->reviewerId);
        $reviewer->recordStart($this->fixture->clock->now());
        $this->fixture->machine('state_machine.reviewer')->apply($reviewer, ReviewerTransition::Start->value);
        $this->fixture->entities->flush();
    }
}
