<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

enum Verdict: string
{
    case Keep = 'keep';
    case Stale = 'stale';
    case Due = 'due';
}
