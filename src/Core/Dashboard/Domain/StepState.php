<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum StepState: string
{
    case Done = 'done';
    case Missing = 'missing';

    public function words(): string
    {
        return match ($this) {
            self::Done => 'Done',
            self::Missing => 'Missing',
        };
    }

    // A missing step takes the amber dot, because it waits for the owner.
    public function dot(): string
    {
        return match ($this) {
            self::Done => 'ok',
            self::Missing => 'needs',
        };
    }
}
