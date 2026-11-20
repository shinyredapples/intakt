<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// A process's parent and start time taken from one read of its /proc entry, so both belong to the same process even when its PID is reused right after.
final readonly class ProcessEntry
{
    public function __construct(
        public int $parent,
        public int $start,
    ) {
    }
}
