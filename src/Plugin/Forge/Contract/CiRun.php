<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Kept as one type for both, because a head is judged by all of them together.
final readonly class CiRun
{
    public function __construct(
        public string $name,
        public CiRunState $state,
    ) {
    }
}
