<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class JobServices
{
    public const string DOCKER_SOCKET = 'intakt.job.docker_socket';
    public const string EGRESS_PROXY_IMAGE = 'intakt.job.egress_proxy_image';
    // The container label that names a job, kept here because the rule for names starting with intakt. puts them in this class.
    public const string JOB_LABEL = 'intakt.job';
}
