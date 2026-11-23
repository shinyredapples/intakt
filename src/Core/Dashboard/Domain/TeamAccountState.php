<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum TeamAccountState: string
{
    case Working = 'Working';
    case CoolingDown = 'Cooling down';
    case KeyRejected = 'Key rejected';

    public function dot(): string
    {
        return match ($this) {
            self::Working => 'ok',
            self::CoolingDown => 'idle',
            self::KeyRejected => 'needs',
        };
    }
}
