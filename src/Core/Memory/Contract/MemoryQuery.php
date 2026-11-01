<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class MemoryQuery
{
    /**
     * @param list<string> $files    the files under review
     * @param int          $maxBytes the size cap of the result, counted in the bytes of the entries' text
     */
    public function __construct(
        public array $files,
        public int $maxBytes,
    ) {
    }
}
