<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// Holds every account at once, in the order they win when several are in effect.
enum SharedHold: string
{
    case Drain = 'drain';
    case Pause = 'pause';
    case BackOff = 'back-off';

    public function decision(): StartDecision
    {
        return match ($this) {
            self::Drain => StartDecision::Draining,
            self::Pause => StartDecision::Paused,
            self::BackOff => StartDecision::BackedOff,
        };
    }

    // A merge calls no model, so only a drain, which lets nothing new start, holds it.
    public function holdsMerges(): bool
    {
        return self::Drain === $this;
    }
}
