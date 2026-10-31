<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsCommandHandler]
final readonly class ReviewWithReviewerHandler
{
    private const string WORKER_STOPPED = 'the worker stopped before the reviewer had a verdict';

    public function __construct(
        private EntityManagerInterface $entities,
        #[Target('reviewer')]
        private WorkflowInterface $reviewerMachine,
        private Vendor $vendor,
        private RoundLedger $ledger,
        private RoundCompletion $rounds,
    ) {
    }

    public function __invoke(ReviewWithReviewer $command): void
    {
        $reviewer = $this->load($command->reviewerId);
        $roundId = $reviewer->round()->id();

        // A redelivered message still tries to complete the round, because the worker that finished this reviewer may have stopped before it did.
        if (!$reviewer->isFinished()) {
            $this->work($reviewer);
        }

        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
    }

    private function load(string $reviewerId): RoundReviewer
    {
        // Cleared because a worker keeps its entity manager between messages, and the stored row is the truth.
        $this->entities->clear();

        $reviewer = $this->entities->find(RoundReviewer::class, Uuid::fromString($reviewerId));

        return $reviewer ?? throw ReviewerNotFound::for($reviewerId);
    }

    private function work(RoundReviewer $reviewer): void
    {
        // Running means an earlier delivery began and never ended; trying again belongs to the retry of the failure handling.
        if (ReviewerState::Running === $reviewer->state()) {
            $this->fail($reviewer, self::WORKER_STOPPED);

            return;
        }

        $this->move($reviewer, ReviewerTransition::Start);
        $this->ledger->reviewerStarted($reviewer);
        $this->review($reviewer);
    }

    private function review(RoundReviewer $reviewer): void
    {
        $round = $reviewer->round();
        $request = new ReviewRequest(new Checkout($round->checkoutRoot()), $round->task(), new ReviewerConversation($reviewer->rolePrompt(), $reviewer->id()));

        try {
            $verdict = $this->vendor->review($round->project(), $reviewer->tier(), $request);
        } catch (ProviderRateLimited|ProviderFailedTransiently|UsageNotRecorded $failure) {
            // The class only, because a provider's message can echo a key.
            $this->fail($reviewer, $failure::class);

            return;
        }

        $reviewer->recordVerdict($verdict);
        $this->move($reviewer, ReviewerTransition::Receive);
        $this->ledger->verdictReceived($reviewer, $verdict);
    }

    private function fail(RoundReviewer $reviewer, string $failure): void
    {
        $reviewer->recordFailure($failure);
        $this->move($reviewer, ReviewerTransition::Fail);
        $this->ledger->reviewerFailed($reviewer, $failure);
    }

    // Flushed at once, so another worker and a restart see the state before the ledger event follows.
    private function move(RoundReviewer $reviewer, ReviewerTransition $step): void
    {
        $this->reviewerMachine->apply($reviewer, $step->value);
        $this->entities->flush();
    }
}
