<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\FindingIds;

// A stored id list read under keys, which are written again as they were, because rows written before the ids were one value are read and written back byte for byte.
final readonly class KeyedFindingIds extends FindingIds
{
    /**
     * @param array<int|string, string> $stored
     */
    public function __construct(public array $stored)
    {
        parent::__construct(...array_values($stored));
    }
}
