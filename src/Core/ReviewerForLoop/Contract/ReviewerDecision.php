<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

final readonly class ReviewerDecision
{
    public function __construct(
        public string $reviewer,
        public ReviewOutcome $outcome,
    ) {
    }
}
