<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// Closed, because the question that people read is worded from these cases and never from the model text (docs/design/01-intent.md, Definition of Ready).
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
