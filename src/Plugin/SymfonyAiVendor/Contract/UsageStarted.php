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
        // The reviewer's id, so a bench can add up one reviewer's calls; left out when null, because the judge's rows and rows written before it was kept never had it.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $reviewer = null,
        // The task a sizing call was made for, so its tokens are charged to the task; left out when null, because no other call names one.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $task = null,
    ) {
    }
}
