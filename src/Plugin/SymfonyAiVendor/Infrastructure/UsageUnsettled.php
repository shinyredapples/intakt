<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

// The payload of a UsageRecorded row for a call that failed; it keeps the usage of the rounds that answered before.
final readonly class UsageUnsettled
{
    /**
     * @param list<TokenUsageRow> $usages
     */
    public function __construct(
        public string $call,
        public string $phase,
        public string $failure,
        public array $usages,
    ) {
    }
}
