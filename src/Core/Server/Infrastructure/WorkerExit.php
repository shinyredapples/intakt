<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

interface WorkerExit
{
    public function end(): void;
}
