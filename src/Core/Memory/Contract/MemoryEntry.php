<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class MemoryEntry
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        public string $project,
        public string $text,
        public array $files,
    ) {
    }

    /**
     * @param list<string> $files
     */
    public function describesAnyOf(array $files): bool
    {
        return [] !== array_intersect($this->files, $files);
    }
}
