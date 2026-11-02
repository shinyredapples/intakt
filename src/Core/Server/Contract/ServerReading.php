<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ServerReading
{
    public function __construct(
        public WorkerThreads|EndpointDown $threads,
        public ServerMetrics|EndpointDown $metrics,
        public HubHealth|EndpointDown $hub,
    ) {
    }
}
