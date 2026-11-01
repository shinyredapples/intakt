<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ChangeReviewRequest
{
    public function __construct(
        public string $project,
        public string $checkoutRoot,
        public string $task,
        public string $diff,
        public RequestedRound $round,
        public VerdictIdentity $identity,
        public Panel $panel,
    ) {
    }
}
