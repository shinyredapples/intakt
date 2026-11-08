<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final readonly class UsageStarted
{
    public function __construct(
        public string $call,
        public string $phase,
        public string $project,
        public Tier $tier,
        // Left out when null, because rows of a call outside any round never had the key.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $round = null,
        // The model the tier resolved to, because Claude Code and Codex answers name none and API answers name a dated snapshot; null on rows written before it was kept.
        public ?string $model = null,
    ) {
    }
}
