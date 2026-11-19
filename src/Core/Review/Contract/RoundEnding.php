<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DateTimeImmutable;

// Carries the reason with the end, because every door must print the same words for it and none may spell them again.
final readonly class RoundEnding
{
    // The time is null when nothing recorded when the round ended, as for a judging Messenger parked before the ledger took failure records.
    public function __construct(
        public RoundEnds $end,
        public string $reason,
        public ?DateTimeImmutable $endedAt = null,
    ) {
    }
}
