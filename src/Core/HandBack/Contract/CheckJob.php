<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class CheckJob
{
    public function __construct(
        public HandBack $handBack,
        public string $image,
        public ?string $configuredCommand,
        // The paths the task's plan names as removed or rewritten, so their loss of content is meant.
        public Paths $namedByThePlan,
    ) {
    }
}
