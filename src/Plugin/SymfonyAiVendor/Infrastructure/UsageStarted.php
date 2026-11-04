<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

// The payload of a UsageRecorded row written before a call; the round is left out of the JSON when the call belongs to none.
final readonly class UsageStarted
{
    public function __construct(
        public string $call,
        public string $phase,
        public string $project,
        public string $tier,
        public ?string $round = null,
    ) {
    }
}
