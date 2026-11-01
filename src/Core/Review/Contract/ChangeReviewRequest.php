<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ChangeReviewRequest
{
    public function __construct(
        public string $project,
        public string $checkoutRoot,
        public string $task,
        // Names the change across its rounds, because they share one finding ledger.
        public string $change,
        public VerdictIdentity $identity,
        public Panel $panel,
        public ?int $round = null,
    ) {
    }
}
