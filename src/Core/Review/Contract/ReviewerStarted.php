<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final readonly class ReviewerStarted
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public Tier $tier,
        // Left out when null, so a start whose tier did not resolve reads like one written before the model was recorded.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $model = null,
    ) {
    }
}
