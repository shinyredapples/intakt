<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// One finished split as its record names it, because only the ledger says which issues are the umbrella's parts.
final readonly class FinishedSplit
{
    /** @var list<int> */
    public array $parts;

    public function __construct(
        public int $umbrella,
        int ...$parts,
    ) {
        $this->parts = array_values($parts);
    }
}
