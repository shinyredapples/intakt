<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

// In the order a build runs them; later parts of the plan add the steps after the clone.
enum BuildStep: string
{
    case Task = 'task';
    case Mirror = 'mirror';
    case Clone = 'clone';
}
