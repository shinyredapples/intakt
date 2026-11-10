<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Announced after the opening transaction commits, because a listener that reads the ledger must find the round's start there.
final readonly class RoundOpened
{
    public function __construct(
        public string $round,
        public string $project,
    ) {
    }
}
