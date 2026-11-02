<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ServerMetrics
{
    public function __construct(
        public int $queueDepth,
        public int $workerRestarts,
    ) {
    }
}
