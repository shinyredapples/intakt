<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final readonly class ProviderWait
{
    public function __construct(
        public Provider $provider,
        public int $seconds,
    ) {
    }
}
