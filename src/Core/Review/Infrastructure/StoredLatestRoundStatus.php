<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

// Waiting on you only once the forge took the verdict, because until then the people on the pull request cannot see what to act on.
final readonly class StoredLatestRoundStatus implements LatestRoundStatus
{
    public function __construct(private ReviewRounds $rounds)
    {
    }

    public function of(string $project, string $change): ?ReviewStatus
    {
        $latest = array_first($this->rounds->ofEverySpelling($project, $change));

        if (!$latest instanceof ReviewRound) {
            return null;
        }

        return $this->isPublishedVerdict($latest) ? ReviewStatus::WaitingOnYou : ReviewStatus::InReview;
    }

    private function isPublishedVerdict(ReviewRound $round): bool
    {
        return RoundState::Judged === $round->state() && null !== $round->publishedAt();
    }
}
