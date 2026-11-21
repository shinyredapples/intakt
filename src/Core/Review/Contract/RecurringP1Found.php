<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class RecurringP1Found
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        // The lowest-numbered judged round of the change that flagged the key, because its head is the one this round's head differs from.
        public string $firstRound,
    ) {
    }
}
