<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders;

use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// The figures of one probe line, in the order it prints them; null for a figure the provider did not report.
final readonly class ProbeUsage
{
    public function __construct(
        public ?string $model,
        public ?int $prompt,
        public ?int $completion,
        public ?int $cached,
        public ?int $total,
        public ?int $remaining,
    ) {
    }

    public static function of(TokenUsageInterface $usage): self
    {
        return new self(
            $usage->getModel(),
            $usage->getPromptTokens(),
            $usage->getCompletionTokens(),
            $usage->getCachedTokens(),
            $usage->getTotalTokens(),
            $usage->getRemainingTokens(),
        );
    }
}
