<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Domain\ReviewTarget;

final readonly class RunReviewRound
{
    public function __construct(
        public ReviewTarget $target,
        public Panel $panel,
        // Named by the caller, for example by the branch under review, because rounds of the same change share one finding ledger.
        public string $change,
    ) {
    }
}
