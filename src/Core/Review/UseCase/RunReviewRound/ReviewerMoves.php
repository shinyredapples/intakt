<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

// Each move of a reviewer and the ledger event that reports it, together, so neither is ever stored without the other.
final readonly class ReviewerMoves
{
    public function __construct(
        #[Target('reviewer')]
        private WorkflowInterface $machine,
        private RoundLedger $ledger,
    ) {
    }

    public function start(RoundReviewer $reviewer, DateTimeImmutable $now): void
    {
        $reviewer->recordStart($now);
        $this->machine->apply($reviewer, ReviewerTransition::Start->value);
        $this->ledger->reviewerStarted($reviewer);
    }

    public function receive(RoundReviewer $reviewer, Verdict $verdict): void
    {
        $reviewer->recordVerdict($verdict);
        $this->machine->apply($reviewer, ReviewerTransition::Receive->value);
        $this->ledger->verdictReceived($reviewer, $verdict);
    }

    public function fail(RoundReviewer $reviewer, string $failure): void
    {
        $reviewer->recordFailure($failure);
        $this->machine->apply($reviewer, ReviewerTransition::Fail->value);
        $this->ledger->reviewerFailed($reviewer, $failure);
    }

    public function block(RoundReviewer $reviewer, string $reason): void
    {
        $reviewer->recordFailure($reason);
        $this->machine->apply($reviewer, ReviewerTransition::Block->value);
        $this->ledger->reviewerBlocked($reviewer, $reason);
    }

    // The start is reported again, because the ledger then shows every attempt a stopped worker cost.
    public function restart(RoundReviewer $reviewer, DateTimeImmutable $now): void
    {
        $reviewer->recordRestart();
        $this->requeue($reviewer);
        $this->start($reviewer, $now);
    }

    // No ledger event, because the ledger refusing one is why a reviewer is handed back; its next start is reported again.
    public function requeue(RoundReviewer $reviewer): void
    {
        $this->machine->apply($reviewer, ReviewerTransition::Requeue->value);
    }
}
