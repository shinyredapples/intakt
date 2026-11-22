<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\RoundTokens;

// One value per reviewer, because the round's ledger rows are read once for all of its reviewers.
final readonly class ReviewerFigures
{
    public function __construct(
        public RoundTokens $tokens,
        public ?int $milliseconds,
        public ModelNames $models,
    ) {
    }
}
