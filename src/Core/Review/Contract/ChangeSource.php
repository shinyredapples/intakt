<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Where a change was read from, recorded with its round so the next round can be started later from the ledger alone.
final readonly class ChangeSource
{
    public function __construct(
        public string $repository,
        public string $base,
    ) {
    }
}
