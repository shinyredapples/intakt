<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class MemoryQuery
{
    public function __construct(
        public string $project,
        public Paths $files,
        public int $maxBytes,
    ) {
    }
}
