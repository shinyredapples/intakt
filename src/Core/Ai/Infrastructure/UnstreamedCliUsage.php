<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Reads the usage of the one-piece answer UnstreamedCliConverter asked for, since the bridge's extractor reads nothing for a call that asked for a stream; excluded from the container because that converter builds it.
#[Exclude]
final readonly class UnstreamedCliUsage implements TokenUsageExtractorInterface
{
    public function __construct(
        private TokenUsageExtractorInterface $inner,
    ) {
    }

    #[Override]
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        return $this->inner->extract($rawResult, UnstreamedCliConverter::unstreamed($options));
    }
}
