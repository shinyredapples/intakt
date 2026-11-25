<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// Three ways, not a yes or no, because a consumer whose working directory cannot be read may still be this install's, so it must not be reported as stopped (decision 1 of #3827, under D5).
enum WorkerProcess
{
    case ThisInstalls;
    case Unverifiable;
    case Other;
}
