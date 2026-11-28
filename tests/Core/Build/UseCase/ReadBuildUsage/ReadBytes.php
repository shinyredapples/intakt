<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ReadBuildUsage;

// Shared with the wrapper through the stream context, because a static count would be state that outlives the test.
final class ReadBytes
{
    private int $total = 0;

    public function add(int $bytes): void
    {
        $this->total += $bytes;
    }

    public function total(): int
    {
        return $this->total;
    }
}
