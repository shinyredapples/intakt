<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\OwnerProposals;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\DocsPartOfDiff;
use Intakt\Core\Review\Infrastructure\OpenFindingIds;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
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
        private LiveRoundProgress $progress,
        private DocsPartOfDiff $docsPart = new DocsPartOfDiff(),
        private OwnerProposals $proposals = new OwnerProposals(),
        private DocsPages $pages = new DocsPages(),
        private OpenFindingIds $ids = new OpenFindingIds(),
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
        $lane = ReviewLane::ofRole($reviewer->roleName());

        try {
            $brief = new ReviewBrief(new ChangeUnderReview($round->project(), $round->task(), $this->diffFor($lane, $round->diff())), $this->openFor($lane, $round), $round->number());
            $request = new ReviewRequest(new Checkout($round->checkoutRoot()), $brief, new ReviewerConversation($reviewer->rolePrompt(), $reviewer->conversationId(), $round->id(), $reviewer->id(), lane: $lane));
            $review = $this->vendor->review($round->project(), $reviewer->tier(), $request);
            $this->refuseWhatTheWriterMayNotSay($lane, $brief, $review->verdict);
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

        $verdict = $this->goalposts->applyToVerdict($round->number(), $this->proposalsLowered($lane, $review->verdict->asRecorded()), $brief->open);
        if ($this->leases->recordVerdict($reviewer, $verdict, $review->substitute)) {
            $this->progress->publishStopOf($reviewer);
        }
    }

    private function diffFor(ReviewLane $lane, string $diff): string
    {
        return ReviewLane::Docs === $lane ? $this->docsPart->of($diff) : $diff;
    }

    // Only the findings on pages it is given, because a reviewer that never saw the code must not be able to close a finding on it.
    /**
     * @return list<Finding>
     */
    private function openFor(ReviewLane $lane, ReviewRound $round): array
    {
        $open = $this->rounds->openFindingsOf($round);

        return ReviewLane::Docs === $lane ? array_values(array_filter($open, fn (Finding $finding): bool => null !== $finding->file && $this->pages->contains($finding->file))) : $open;
    }

    // Here too and not only in the reviewer agent, because the ledger closes any id a recorded verdict names, whichever vendor answered.
    // Not Intakt's own failure verdicts, because their one finding has no file and holds the reason the review failed.
    private function refuseWhatTheWriterMayNotSay(ReviewLane $lane, ReviewBrief $brief, Verdict $verdict): void
    {
        if (ReviewLane::Docs !== $lane || $verdict->isIntakts()) {
            return;
        }

        $this->ids->checkedAgainst($brief, $verdict);
        $this->refuseFindingsOffThePages($verdict);
        $this->refuseRepeatedProposals($verdict);
    }

    // Refused like a foreign id, because the writer never saw the code: a new finding there would block the head, and a repeat there would move an open finding onto code. Key-safe, because the reason names no text of the model.
    private function refuseFindingsOffThePages(Verdict $verdict): void
    {
        foreach ($verdict->findings as $finding) {
            if (null === $finding->file || !$this->pages->contains($finding->file)) {
                throw VerdictUnreadable::becauseKeySafe('the Technical writer reported a finding outside the docs pages.');
            }
        }
    }

    // Refused, because the goalpost rule gives a repeat the open finding's severity and would undo the owner's P3 a proposal is lowered to.
    private function refuseRepeatedProposals(Verdict $verdict): void
    {
        foreach ($verdict->findings as $finding) {
            if ($finding->repeat && $finding->namesProposal()) {
                throw VerdictUnreadable::becauseKeySafe('the Technical writer repeated an open finding as a proposal.');
            }
        }
    }

    // Here too and not only in the reviewer agent, because another vendor answers without it and the recorded verdict must hold the lowered severity.
    private function proposalsLowered(ReviewLane $lane, Verdict $verdict): Verdict
    {
        return ReviewLane::Docs === $lane ? $this->proposals->applyToVerdict($verdict) : $verdict;
    }
}
