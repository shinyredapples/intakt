<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

enum LeftOutCause: string
{
    case AtShortWindowCeiling = 'short-window-ceiling';
    case AtWeeklyCeiling = 'weekly-ceiling';
    case CoolingDown = 'cooling-down';
}
