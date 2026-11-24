<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

enum HubState
{
    case Healthy;
    case Unhealthy;
    case Down;
}
