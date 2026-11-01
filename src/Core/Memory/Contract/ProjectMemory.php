<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// Retrieval only: what a reviewer is handed has no way to write.
interface ProjectMemory
{
    public function relevantTo(MemoryQuery $query): RelevantMemory;
}
