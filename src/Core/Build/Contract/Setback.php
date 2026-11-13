<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// A round with no P1 finding is no setback, so the caller passes none for it.
enum Setback: string
{
    case FailedAttempt = 'failed-attempt';
    case BlockingFinding = 'blocking-finding';
    case Stall = 'stall';
}
