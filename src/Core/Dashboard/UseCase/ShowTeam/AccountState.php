<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

enum AccountState: string
{
    case Ready = 'ready';
    case CoolingDown = 'cooling-down';
    case Rejected = 'rejected';
    case NoKey = 'no-key';
}
