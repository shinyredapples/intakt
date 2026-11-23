<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

// Each move of a reviewer and the ledger event that reports it, together, so neither is ever stored without the other.
final readonly class ReviewerMoves
{
    public function __construct(
        #[Target('reviewer')]
        private WorkflowInterface $machine,
        private RoundLedger $ledger,
        #[Target('stored')]
        private SerializerInterface $serializer,
        private StopRequests $stops,
    ) {
    }

    public function start(RoundReviewer $reviewer, DateTimeImmutable $now): void
    {
        $reviewer->recordStart($now);
        $this->machine->apply($reviewer, ReviewerTransition::Start->value);
        $this->ledger->reviewerStarted($reviewer);
    }

    // Says whether it answered a stop request, because only this decision may show the reviewer as stopped: the ledger read again later can hold a newer request or an older run's stop.
    #[NoDiscard]
    public function receive(RoundReviewer $reviewer, VendorReview $review): bool
    {
        $verdict = $review->verdict;
        $reviewer->recordVerdict($this->serializer->serialize(ReviewerAnswer::fromVerdict($verdict), JsonEncoder::FORMAT), $review->substitute);
        $this->machine->apply($reviewer, ReviewerTransition::Receive->value);
        $this->ledger->verdictReceived($reviewer, $verdict);
        $stopped = $this->answerStopRequest($reviewer, $verdict);

        if ($review->substitute instanceof Provider) {
            $this->ledger->catchUpOwed($reviewer, $review->substitute, $review->substituteModel);
        }

        return $stopped;
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

    // Only for a pending request, because a model can name the stopped finding itself, and only the owner's request may make the ledger say a reviewer was stopped.
    private function answerStopRequest(RoundReviewer $reviewer, Verdict $verdict): bool
    {
        if (!$verdict->isStopped() || !$this->stops->pendingFor($reviewer->round()->id(), $reviewer->id())) {
            return false;
        }

        $this->ledger->reviewerStopped($reviewer);

        return true;
    }
}
