<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

enum JobEndCause: string
{
    case Exited = 'exited';
    case MemoryKilled = 'memory_killed';
    case TimedOut = 'timed_out';
}
