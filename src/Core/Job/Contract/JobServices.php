<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class JobServices
{
    public const string DOCKER_SOCKET = 'intakt.job.docker_socket';
}
