<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerStillHeld;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Two workers on two connections, the second one delivering the same message while the first is inside its vendor call: the order is forced by the vendor double, never by timing.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[CoversClass(ReviewerStillHeld::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
final class DuplicateDeliveryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testASecondDeliveryDuringALiveVendorCallLeavesTheReviewerToItsWorker(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $second = $this->fixture->secondWorker(new ScriptedVendor());
        $seenMeanwhile = [];

        $this->fixture->deliver($message, new RedeliveredDuringTheCall(function () use ($second, $message, $round, &$seenMeanwhile): void {
            try {
                $second->handler->__invoke($message);
                self::fail('The second worker took over a reviewer the first one still held.');
            } catch (ReviewerStillHeld $held) {
                $seenMeanwhile = [$held->getRetryDelay(), $second->roundState($round->id()), $second->reviewerState($message->reviewerId)];
            }
        }));

        self::assertSame([60 * 60 * 1000, RoundState::Collecting, ReviewerState::Running], $seenMeanwhile);
        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived], $this->fixture->ledgerKinds());
        self::assertSame([], $second->ledger->kinds());
    }

    public function testAVerdictArrivingAfterTheHoldEndedAndAnotherWorkerFailedTheReviewerIsDropped(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $second = $this->fixture->secondWorker(new ScriptedVendor());
        $seenMeanwhile = [];

        $this->fixture->deliver($message, new RedeliveredDuringTheCall(function () use ($second, $message, $round, &$seenMeanwhile): void {
            $this->fixture->clock->modify('+61 minutes');
            $second->handler->__invoke($message);
            $seenMeanwhile = [$second->roundState($round->id()), $second->reviewerState($message->reviewerId)];
        }));

        self::assertSame([RoundState::Complete, ReviewerState::Failed], $seenMeanwhile);
        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertNull($stored->reviewers()[0]->verdictJson());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted], $this->fixture->ledgerKinds());
        self::assertSame([LedgerEventKind::ReviewerFailed], $second->ledger->kinds());
    }
}
