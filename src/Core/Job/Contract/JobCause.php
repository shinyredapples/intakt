<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

enum JobCause: string
{
    case Exited = 'exited';
    case MemoryKilled = 'oom';
    case TimedOut = 'timed_out';
}
