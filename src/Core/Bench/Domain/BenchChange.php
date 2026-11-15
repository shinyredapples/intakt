<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\ChangeReviewRequest;

// One bench change ready to review: what the review is asked and what a correct review would find.
final readonly class BenchChange
{
    public function __construct(
        public string $name,
        public Expectations $expectations,
        public ChangeFiles $files,
        public ChangeReviewRequest $request,
    ) {
    }
}
