<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EndpointDown
{
    public function __construct(
        public string $reason,
    ) {
    }
}
