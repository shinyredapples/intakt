<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

// Shared by every wrapper instance through the stream context, because a static count would be state that outlives the test.
final class Opens
{
    private int $total = 0;

    public function count(): void
    {
        ++$this->total;
    }

    public function total(): int
    {
        return $this->total;
    }
}
