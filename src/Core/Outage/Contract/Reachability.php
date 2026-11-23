<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Contract;

enum Reachability: string
{
    case Online = 'online';
    case Offline = 'offline';
}
