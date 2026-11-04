<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

// The payload of a CatchUpOwed row.
final readonly class CatchUpOwed
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public string $tier,
        public string $substitute,
    ) {
    }
}
