<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\CommandMessage;

// Only the id, because the stored row holds the rest and a message that waits in the queue stays valid across a restart.
final readonly class ReviewWithReviewer implements CommandMessage
{
    public function __construct(public string $reviewerId)
    {
    }
}
