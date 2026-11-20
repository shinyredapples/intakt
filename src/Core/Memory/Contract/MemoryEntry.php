<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class MemoryEntry
{
    public function __construct(
        public string $project,
        public string $text,
        public Paths $files,
    ) {
    }

    public function describesAnyOf(Paths $files): bool
    {
        return $this->files->sharesAnyWith($files);
    }
}
