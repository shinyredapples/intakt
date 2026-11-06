<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

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
