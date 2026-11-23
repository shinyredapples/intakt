<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

// Retrieval only, because a reviewer is handed this and must have no way to write.
interface ProjectMemory
{
    public function relevantTo(MemoryQuery $query): RelevantMemory;

    public function settledOn(SettledQuery $query): SettledMemory;
}
