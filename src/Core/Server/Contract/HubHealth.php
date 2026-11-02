<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

enum HubHealth: string
{
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
}
