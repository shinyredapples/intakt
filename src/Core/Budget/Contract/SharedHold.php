<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// In the order they win when several are in effect, so a hold that stops merges too comes before one that lets them through.
enum SharedHold: string
{
    case Drain = 'drain';
    case FailureBackOff = 'failure-back-off';
    case Pause = 'pause';
    case BudgetBackOff = 'budget-back-off';

    public function decision(): StartDecision
    {
        return match ($this) {
            self::Drain => StartDecision::Draining,
            self::FailureBackOff => StartDecision::FailureBackedOff,
            self::Pause => StartDecision::Paused,
            self::BudgetBackOff => StartDecision::BudgetBackedOff,
        };
    }

    // A merge calls no model, so a budget back-off and a pause let approved heads land; a drain lets nothing new start, and a failure may lie in the landing itself.
    public function holdsMerges(): bool
    {
        return match ($this) {
            self::Drain, self::FailureBackOff => true,
            self::Pause, self::BudgetBackOff => false,
        };
    }
}
