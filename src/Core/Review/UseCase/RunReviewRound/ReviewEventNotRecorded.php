<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use RuntimeException;

final class ReviewEventNotRecorded extends RuntimeException
{
    public static function for(string $kind, string $failure): self
    {
        return new self(sprintf('The ledger did not store the %s event: %s', $kind, $failure));
    }
}
