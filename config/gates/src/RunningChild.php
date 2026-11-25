<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Process;

final readonly class RunningChild
{
    public function __construct(
        public Process $process,
        public ProcessGroup $group,
        public float $deadline,
        public string $program,
        public int|float $seconds,
    ) {
    }
}
