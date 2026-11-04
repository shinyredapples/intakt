<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;

// A null round is left out of the JSON because rows of a call outside any round never had the key.
final readonly class UsageStarted
{
    public function __construct(
        public string $call,
        public string $phase,
        public string $project,
        public Tier $tier,
        public ?string $round = null,
    ) {
    }
}
