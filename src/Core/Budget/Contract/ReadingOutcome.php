<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

enum ReadingOutcome: string
{
    case Recorded = 'recorded';
    case ResetAlreadyPassed = 'reset-already-passed';
    case ResetBeyondWindow = 'reset-beyond-window';
}
