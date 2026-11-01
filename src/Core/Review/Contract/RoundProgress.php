<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the review builds it per poll, not the container; the flag is data and not a mode.
#[Exclude]
final readonly class RoundProgress
{
    public function __construct(
        public int $reviewersFinished,
        public int $reviewers,
        public bool $judged,
    ) {
    }
}
