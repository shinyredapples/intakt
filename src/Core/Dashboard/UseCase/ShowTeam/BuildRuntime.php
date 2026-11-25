<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

enum BuildRuntime: string
{
    case Runs = 'runs';
    case CannotRun = 'cannot-run';
}
