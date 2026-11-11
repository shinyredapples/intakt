<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

enum ContainerHealth
{
    case Starting;
    case Healthy;
    case Unhealthy;
    case Stopped;
}
