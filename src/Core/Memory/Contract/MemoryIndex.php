<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// Held by the orchestrator only; reviewers get ProjectMemory.
interface MemoryIndex
{
    public function index(MemoryEntry $entry): void;
}
