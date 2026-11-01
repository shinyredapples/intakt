<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Override;

final readonly class RemembersNothing implements ProjectMemory
{
    #[Override]
    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        return RelevantMemory::within([], $query->maxBytes);
    }
}
