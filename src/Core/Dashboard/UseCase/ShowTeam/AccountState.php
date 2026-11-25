<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

enum AccountState: string
{
    case Ready = 'ready';
    case CoolingDown = 'cooling-down';
    case Rejected = 'rejected';
    case NoKey = 'no-key';

    public function inWords(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::CoolingDown => 'Cooling down',
            self::Rejected => 'Key rejected',
            self::NoKey => 'No key set',
        };
    }

    // Red only for a rejected key, because only the owner can bring it back; a cooldown ends by itself, and amber belongs to what waits on the owner (DESIGN.md, Amber audit).
    public function dot(): string
    {
        return match ($this) {
            self::Ready => 'ok',
            self::Rejected => 'broken',
            self::CoolingDown, self::NoKey => 'idle',
        };
    }
}
