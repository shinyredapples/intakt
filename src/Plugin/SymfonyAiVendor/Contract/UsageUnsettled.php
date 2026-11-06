<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

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
