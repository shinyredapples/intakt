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
        // The number the caller expects the round to get, checked once the number is taken under the change's lock, because a round another caller opened first would otherwise take it unnoticed.
        public ?int $round = null,
    ) {
    }
}
