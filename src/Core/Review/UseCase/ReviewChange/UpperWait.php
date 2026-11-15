<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Domain\RoundReviewer;

// Every reviewer's time limit added up, as one worker runs them one after another, plus the judge's, so a slow but healthy round is never given up on and no cause can make the wait endless.
final readonly class UpperWait
{
    // The judge is one model call, which the vendor holds no longer than a review, so it gets a review's limit.
    public const int JUDGE_SECONDS = RoundReviewer::REVIEW_CEILING_SECONDS;

    public function __construct(
        private int $reviewerSeconds = RoundReviewer::REVIEW_CEILING_SECONDS,
        private int $judgeSeconds = self::JUDGE_SECONDS,
    ) {
    }

    public function secondsFor(Panel $panel): int
    {
        return $this->secondsForReviewers(count($panel->reviewers));
    }

    public function secondsForReviewers(int $reviewers): int
    {
        return $reviewers * $this->reviewerSeconds + $this->judgeSeconds;
    }
}
