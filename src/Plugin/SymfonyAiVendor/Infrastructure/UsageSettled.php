<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

// The payload of a UsageRecorded row written once a call's usage is known.
final readonly class UsageSettled
{
    /**
     * @param list<TokenUsageRow> $usages
     */
    public function __construct(
        public string $call,
        public string $phase,
        public array $usages,
    ) {
    }
}
