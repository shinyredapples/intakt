<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

final readonly class ReviewerDecision
{
    public function __construct(
        public string $reviewer,
        public ReviewOutcome $outcome,
    ) {
    }
}
