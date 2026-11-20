<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The round that flagged the place again, the finding there and its key, so a later step can escalate that finding alone.
final readonly class RecurringP1Found
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
    ) {
    }
}
