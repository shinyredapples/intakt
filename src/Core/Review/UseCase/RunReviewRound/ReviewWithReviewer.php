<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

// Only the id, because the stored row holds the rest and a message that waits in the queue stays valid across a restart.
final readonly class ReviewWithReviewer
{
    public function __construct(public string $reviewerId)
    {
    }
}
