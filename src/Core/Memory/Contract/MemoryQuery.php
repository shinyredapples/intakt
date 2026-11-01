<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class MemoryQuery
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        public array $files,
        public int $maxBytes,
    ) {
    }
}
