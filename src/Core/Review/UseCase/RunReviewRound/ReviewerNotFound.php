<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use RuntimeException;

final class ReviewerNotFound extends RuntimeException
{
    public static function for(string $reviewerId): self
    {
        return new self('No reviewer is stored under the id '.$reviewerId);
    }
}
