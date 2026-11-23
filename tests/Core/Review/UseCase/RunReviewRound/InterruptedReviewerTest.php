<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(ReviewerMoves::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
final class InterruptedReviewerTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAnInterruptionKeepsTheAgentSessionAndLeavesRestartsAndFailureUnchanged(): void
    {
        $id = $this->interruptedReviewerId('session-1');

        $stored = $this->fixture->reviewer($id);
        self::assertSame(ReviewerState::Interrupted, $stored->state());
        self::assertSame('session-1', $stored->agentSessionId());
        self::assertSame(1, $stored->interruptions());
        self::assertFalse($stored->hasRestarted());
        self::assertNull($stored->failure());
    }

    public function testARedeliveredMessageResumesAnInterruptedReviewerWithItsSessionAndWithoutUsingUpTheRetry(): void
    {
        $id = $this->interruptedReviewerId('session-1');

        $resumed = $this->fixture->leases($this->fixture->entities)->take($id);

        self::assertSame(ReviewerState::Running, $resumed->state());
        self::assertSame('session-1', $resumed->agentSessionId());
        self::assertFalse($resumed->hasRestarted());
    }

    public function testManyInterruptionsNeverFailOrBlockTheReviewer(): void
    {
        $id = $this->interruptedReviewerId('session-1');
        $leases = $this->fixture->leases($this->fixture->entities);

        for ($outage = 0; $outage < 5; ++$outage) {
            $leases->recordInterrupted($leases->take($id));
        }

        $stored = $this->fixture->reviewer($id);
        self::assertSame(ReviewerState::Interrupted, $stored->state());
        self::assertSame(6, $stored->interruptions());
        self::assertFalse($stored->hasRestarted());
        self::assertNull($stored->failure());
    }

    public function testAnOutageReportedAfterTheReviewCeilingStillWaitsWithItsSession(): void
    {
        $this->fixture->start(1);
        $id = $this->fixture->bus->messages[0]->reviewerId;
        $leases = $this->fixture->leases($this->fixture->entities);
        $reviewer = $leases->take($id);
        $reviewer->recordAgentSession('session-1');
        $this->fixture->entities->flush();
        $this->fixture->clock->modify('+1 hour');

        $leases->recordInterrupted($reviewer);

        $stored = $this->fixture->reviewer($id);
        self::assertSame(ReviewerState::Interrupted, $stored->state());
        self::assertSame('session-1', $stored->agentSessionId());
        self::assertNull($stored->failure());
    }

    public function testAResultReportedAfterTheReviewCeilingStillFailsTheReviewer(): void
    {
        $this->fixture->start(1);
        $leases = $this->fixture->leases($this->fixture->entities);
        $reviewer = $leases->take($this->fixture->bus->messages[0]->reviewerId);
        $this->fixture->clock->modify('+1 hour');

        $leases->recordFailure($reviewer, 'the model refused');

        $stored = $this->fixture->reviewer($reviewer->id()->toRfc4122());
        self::assertSame(ReviewerState::Failed, $stored->state());
        self::assertSame('the reviewer had no result within the review ceiling', $stored->failure());
    }

    public function testARealFailureStillMovesTheReviewerToFailedWithTheFailureStored(): void
    {
        $this->fixture->start(1);
        $leases = $this->fixture->leases($this->fixture->entities);
        $reviewer = $leases->take($this->fixture->bus->messages[0]->reviewerId);

        $leases->recordFailure($reviewer, 'the model refused');

        $stored = $this->fixture->reviewer($reviewer->id()->toRfc4122());
        self::assertSame(ReviewerState::Failed, $stored->state());
        self::assertSame('the model refused', $stored->failure());
        self::assertSame(0, $stored->interruptions());
    }

    private function interruptedReviewerId(string $session): string
    {
        $this->fixture->start(1);
        $id = $this->fixture->bus->messages[0]->reviewerId;
        $leases = $this->fixture->leases($this->fixture->entities);
        $reviewer = $leases->take($id);
        $reviewer->recordAgentSession($session);
        $this->fixture->entities->flush();
        $leases->recordInterrupted($reviewer);

        return $id;
    }
}
