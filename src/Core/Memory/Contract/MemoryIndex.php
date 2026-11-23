<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// Kept apart from ProjectMemory because reviewers must be able to read memory without being able to write it.
interface MemoryIndex
{
    public function index(MemoryEntry $entry): void;

    // Settling the same finding again replaces it, so a new hash after a code change refreshes the entry instead of adding one.
    public function settle(SettledFinding $finding): void;

    public function forget(SettledFinding $finding): void;
}
