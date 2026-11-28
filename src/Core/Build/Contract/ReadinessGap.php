<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The first point of the Definition of Ready (docs/design/01-intent.md), one case per thing a task can lack.
enum ReadinessGap: string
{
    case ObservableResult = 'observable_result';
    case AcceptanceCondition = 'acceptance_condition';
    case SingleOutcome = 'single_outcome';

    public function wording(): string
    {
        return match ($this) {
            self::ObservableResult => 'an observable result',
            self::AcceptanceCondition => 'an acceptance condition',
            self::SingleOutcome => 'a single outcome (the task states more than one)',
        };
    }
}
