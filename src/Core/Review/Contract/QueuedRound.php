<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Names the checkout along with the round, because a caller whose own record of the round rolls back must have the checkout discarded, and the round's row that names it is gone with the rollback.
final readonly class QueuedRound
{
    public function __construct(
        public string $roundId,
        public string $checkoutRoot,
        // Answered with the round, because a later check of its verdicts must judge them by the labels it started with, not by labels read again.
        public Labels $labels = new Labels(),
    ) {
    }
}
