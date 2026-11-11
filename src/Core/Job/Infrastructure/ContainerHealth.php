<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// The values are the engine's Health.Status strings; Stopped is read from State.Running instead, never from Health.Status.
enum ContainerHealth: string
{
    case Starting = 'starting';
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case Stopped = 'stopped';
}
