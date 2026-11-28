<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\SizingVerdict;

enum SizingOutcome: string
{
    case Ready = 'ready';
    case Parked = 'parked';
    // The answer was no valid verdict, so nothing was parked and nothing split.
    case Invalid = 'invalid';
    // The task's latest ready or parked verdict is on the same text, so no call was made.
    case AlreadySized = 'already_sized';
    // Also for a split interrupted before and finished from its plan, because that run is the one that files the remaining parts.
    case Split = 'split';

    // Not ready with nothing missing is invalid too, because there would be nothing to ask; the agent already refuses such an answer.
    public static function of(?SizingVerdict $verdict): self
    {
        return match (true) {
            !$verdict instanceof SizingVerdict => self::Invalid,
            $verdict->ready => self::Ready,
            [] === $verdict->missing => self::Invalid,
            default => self::Parked,
        };
    }
}
