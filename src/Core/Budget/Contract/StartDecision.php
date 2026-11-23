<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

enum StartDecision: string
{
    case Start = 'start';
    case AtCeiling = 'at-ceiling';
    case NoTrustedReading = 'no-trusted-reading';
    case ReadingNotFreshEnough = 'reading-not-fresh-enough';
    case NoMarginForParallelStart = 'no-margin-for-parallel-start';
    case CoolingDown = 'cooling-down';
    case BudgetBackedOff = 'budget-backed-off';
    case Paused = 'paused';
    case FailureBackedOff = 'failure-backed-off';
    case Draining = 'draining';
    case Halted = 'halted';

    public function admits(): bool
    {
        return self::Start === $this;
    }
}
