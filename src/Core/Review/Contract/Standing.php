<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum Standing: string
{
    case Current = 'current';
    case KeptForUntouchedParts = 'kept-for-untouched-parts';
    case Stale = 'stale';
}
