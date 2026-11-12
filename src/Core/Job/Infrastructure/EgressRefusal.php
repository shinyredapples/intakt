<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

final readonly class EgressRefusal
{
    public function __construct(
        public string $method,
        public string $target,
    ) {
    }
}
