<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Throwable;

#[AsCommandHandler]
final readonly class ReviewWithReviewerHandler
{
    public function __construct(
        private ReviewerLeases $leases,
        private Vendor $vendor,
        private ReviewRounds $rounds,
        private GoalpostRule $goalposts,
    ) {
    }

    public function __invoke(ReviewWithReviewer $command): void
    {
        $reviewer = $this->leases->take($command->reviewerId);
        $roundId = $reviewer->round()->id();

        // Running means this worker holds it now; a finished one still tries to complete the round, because the worker that finished it may have stopped before it did.
        if (ReviewerState::Running === $reviewer->state()) {
            $this->review($reviewer);
        }

        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
    }

    private function review(RoundReviewer $reviewer): void
    {
        $round = $reviewer->round();

        try {
            $brief = new ReviewBrief($round->task(), $round->diff(), $this->rounds->openFindingsOf($round));
            $request = new ReviewRequest(new Checkout($round->checkoutRoot()), $brief, new ReviewerConversation($reviewer->rolePrompt(), $reviewer->conversationId()));
            $review = $this->vendor->review($round->project(), $reviewer->tier(), $request);
        } catch (ProviderRateLimited $limited) {
            $this->leases->handBack($reviewer);

            throw ReviewerRateLimited::after($limited->retryAfterSeconds);
        } catch (VendorAccountBusy) {
            $this->leases->handBack($reviewer);

            throw ReviewerRateLimited::after(null);
        } catch (VendorAccountOut) {
            $this->leases->handBack($reviewer);

            throw ReviewerMovesToAnotherAccount::now();
        } catch (Throwable $failure) {
            // Every failure, because one left uncaught would leave the reviewer running until its hold ends; the class only, because a provider's message can echo a key.
            $this->leases->recordFailure($reviewer, $failure::class);

            return;
        }

        $this->leases->recordVerdict($reviewer, $this->goalposts->applyToVerdict($round->number(), $review->verdict, $brief->open));
    }
}
