<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

enum Verdict: string
{
    case OutOfBounds = 'out-of-bounds';
    case Keep = 'keep';
    case Stale = 'stale';
    case Warn = 'warn';
    case Deletable = 'deletable';
}
