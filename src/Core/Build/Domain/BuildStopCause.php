<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Why a build over its allowance was stopped, so the limits can be tuned from the recorded causes (docs/design/05-architecture/06-budget-and-model-planner.md, "Allowance per task").
enum BuildStopCause: string
{
    case NoProgress = 'no_progress';
    case RunawayCeiling = 'runaway_ceiling';
    case PerTaskTotal = 'per_task_total';
}
