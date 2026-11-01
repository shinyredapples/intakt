<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// Kept apart from ProjectMemory because reviewers must be able to read memory without being able to write it.
interface MemoryIndex
{
    public function index(MemoryEntry $entry): void;
}
