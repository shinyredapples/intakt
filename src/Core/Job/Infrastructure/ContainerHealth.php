<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// Stopped is never read from Health.Status, because the engine keeps the last status of a container that exited.
enum ContainerHealth: string
{
    case Starting = 'starting';
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case Stopped = 'stopped';
}
