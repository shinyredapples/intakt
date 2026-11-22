<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\RoundTokens;

// What the ledger recorded for one reviewer of a round, read for all of its reviewers at once.
final readonly class ReviewerFigures
{
    public function __construct(
        public RoundTokens $tokens,
        // Null while the reviewer has no end row.
        public ?int $milliseconds,
        public ModelNames $models,
    ) {
    }
}
