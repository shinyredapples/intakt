<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The container parameter names and the label of this module, so config and code never spell one twice.
final readonly class JobServices
{
    public const string DOCKER_SOCKET = 'intakt.job.docker_socket';
    public const string PROXY_IMAGE = 'intakt.job.proxy_image';

    // Every container and network of a job carries this label with the job id, so a sweep finds what a crash left.
    public const string JOB_LABEL = 'intakt.job';
}
