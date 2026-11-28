<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

enum SizingOutcome: string
{
    case Ready = 'ready';
    case Parked = 'parked';
    // The answer was no valid verdict, so nothing was parked and nothing split.
    case Invalid = 'invalid';
    // A ready or parked verdict on the same text is already recorded, so no call was made.
    case AlreadySized = 'already_sized';
}
