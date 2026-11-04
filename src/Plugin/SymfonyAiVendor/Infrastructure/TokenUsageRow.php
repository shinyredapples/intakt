<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

// One reading of a vendor's token usage; a value the vendor did not report stays null in the JSON, because rows have always kept it.
final readonly class TokenUsageRow
{
    public function __construct(
        public ?string $account,
        public ?string $model,
        public ?int $prompt,
        public ?int $completion,
        public ?int $thinking,
        public ?int $tool,
        public ?int $cached,
        public ?int $cacheCreation,
        public ?int $cacheRead,
        public ?int $total,
        public ?int $remaining,
        public ?int $remainingMinute,
        public ?int $remainingMonth,
    ) {
    }
}
