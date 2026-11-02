<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// One workflow run or check suite of a head.
final readonly class CiRun
{
    public function __construct(
        public string $name,
        public CiRunState $state,
    ) {
    }
}
