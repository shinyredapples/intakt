<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\ReviewerOutcome;
use Intakt\Core\Review\Contract\Verdict;

// Reviews through the normal path (panel, judge, ledger), because a bench of another path would measure nothing a user gets.
final readonly class ReviewBenchChange
{
    public function __construct(
        private ChangeReviews $reviews,
        private RunClassifier $classifier,
    ) {
    }

    public function of(BenchChange $change): BenchReview
    {
        $review = $this->reviews->review($change->request);

        return new BenchReview(
            $change->name,
            $review->decision,
            array_map(fn (ReviewerOutcome $reviewer): BenchReviewerResult => $this->resultOf($change, $reviewer), $review->reviewers),
            $review->actList,
        );
    }

    private function resultOf(BenchChange $change, ReviewerOutcome $reviewer): BenchReviewerResult
    {
        $verdict = $this->classifier->classify($change->expectations, $change->files, $this->outcomeOf($reviewer));

        return new BenchReviewerResult($reviewer->role, $reviewer->tier, $verdict, $reviewer->tokens, $reviewer->milliseconds, $reviewer->models);
    }

    // A reviewer that failed or gave a failure verdict reviewed nothing, so its misses are the harness's and never the reviewer's.
    private function outcomeOf(ReviewerOutcome $reviewer): RunOutcome
    {
        if (null !== $reviewer->failure) {
            return RunOutcome::failed($reviewer->failure);
        }

        if (!$reviewer->verdict instanceof Verdict) {
            return RunOutcome::failed('the reviewer gave no verdict');
        }

        if ($reviewer->verdict->isFailedReview()) {
            return RunOutcome::failed('the reviewer could not complete its review');
        }

        return RunOutcome::completed($reviewer->verdict->findings);
    }
}
