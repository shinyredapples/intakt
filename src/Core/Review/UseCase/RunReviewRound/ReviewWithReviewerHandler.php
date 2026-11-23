<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Throwable;

#[AsCommandHandler]
final readonly class ReviewWithReviewerHandler
{
    public function __construct(
        private ReviewerLeases $leases,
        private Vendor $vendor,
        private ReviewRounds $rounds,
        private GoalpostRule $goalposts,
        private LiveRoundProgress $progress,
    ) {
    }

    public function __invoke(ReviewWithReviewer $command): void
    {
        $reviewer = $this->leases->take($command->reviewerId);
        $roundId = $reviewer->round()->id();

        // Running means this worker holds it now; a finished one still tries to complete the round, because the worker that finished it may have stopped before it did.
        if (ReviewerState::Running === $reviewer->state()) {
            $this->progress->publishStartOf($reviewer);

            try {
                $this->review($reviewer);
                // Boundary: republishes the stored round and rethrows, because a review that throws may have handed its reviewer back and lowered the started count, or failed while storing its verdict and left the reviewer running.
            } catch (Throwable $handedBack) {
                $this->progress->publishLatest($roundId);

                throw $handedBack;
            }
        }

        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
        $this->progress->publishLatest($roundId);
    }

    private function review(RoundReviewer $reviewer): void
    {
        $round = $reviewer->round();

        try {
            $brief = new ReviewBrief(new ChangeUnderReview($round->project(), $round->task(), $round->diff()), $this->rounds->openFindingsOf($round), $round->number());
            $request = new ReviewRequest(new Checkout($round->checkoutRoot()), $brief, new ReviewerConversation($reviewer->rolePrompt(), $reviewer->conversationId(), $round->id(), $reviewer->id()));
            $review = $this->vendor->review($round->project(), $reviewer->tier(), $request);
        } catch (ProviderRateLimited $limited) {
            $this->leases->handBack($reviewer);

            throw ReviewerRateLimited::after($limited->retryAfterSeconds);
        } catch (VendorAccountBusy) {
            $this->leases->handBack($reviewer);

            throw ReviewerRateLimited::after(null);
        } catch (NoProviderLeft $outage) {
            $this->leases->recordOutage($reviewer, $outage->getMessage());

            return;
        } catch (VendorAccountOut) {
            $this->leases->handBack($reviewer);

            throw ReviewerMovesToAnotherAccount::now();
        } catch (VerdictUnreadable $unreadable) {
            $this->leases->recordFailure($reviewer, $unreadable->keySafeInWords() ?? $unreadable::class);

            return;
            // Boundary: records every failure because one left uncaught would leave the reviewer running until its hold ends, and keeps the class only because a provider's message can echo a key.
        } catch (Throwable $failure) {
            $this->leases->recordFailure($reviewer, $failure::class);

            return;
        }

        $verdict = $this->goalposts->applyToVerdict($round->number(), $review->verdict->asRecorded(), $brief->open);
        if ($this->leases->recordVerdict($reviewer, new VendorReview($verdict, $review->substitute, $review->substituteModel))) {
            $this->progress->publishStopOf($reviewer);
        }
    }
}
