<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

enum FailureCause: string
{
    case Reviewer = 'reviewer';
    case Bench = 'bench';
    case Harness = 'harness';
}
