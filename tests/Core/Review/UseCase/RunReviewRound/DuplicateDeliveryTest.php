<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerStillHeld;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Two workers on two connections, the second one delivering the same message while the first is inside its vendor call, because that is the interleaving a duplicate delivery produces; the vendor double forces the order, so no timing is involved.
#[Group('persistence')]
#[CoversNothing]
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

    public function testAVerdictArrivingAfterTheHoldEndedAndAnotherWorkerRestartedTheReviewerIsDropped(): void
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

        self::assertSame([RoundState::Complete, ReviewerState::Verdict], $seenMeanwhile);
        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted], $this->fixture->ledgerKinds());
        self::assertSame([LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived], $second->ledger->kinds());
    }

    public function testAVerdictOfAWorkerWhoseReviewerWasRestartedLeavesTheRestartedAttemptRunning(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        $this->fixture->deliver($message, new RedeliveredDuringTheCall(function () use ($message): void {
            $this->fixture->clock->modify('+61 minutes');
            $this->fixture->takeAndStop($message);
        }));

        self::assertSame(ReviewerState::Running, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::ReviewerStarted], $this->fixture->ledgerKinds());
    }

    public function testARateLimitOfAWorkerWhoseReviewerWasRestartedDoesNotRequeueTheRestartedAttempt(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $limited = new RedeliveredDuringTheCall(function () use ($message): void {
            $this->fixture->clock->modify('+61 minutes');
            $this->fixture->takeAndStop($message);
        }, new ProviderRateLimited(30, new RuntimeException('limited')));

        try {
            $this->fixture->deliver($message, $limited);
            self::fail('The rate limit was not passed on to Messenger.');
        } catch (ReviewerRateLimited) {
            self::assertSame(ReviewerState::Running, $this->fixture->reviewer($message->reviewerId)->state());
        }
    }
}
