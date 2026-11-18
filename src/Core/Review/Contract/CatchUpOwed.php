<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final readonly class CatchUpOwed
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public Tier $tier,
        public Provider $substitute,
        // Null in a row written before the substitute's model was recorded, or when the tier no longer names that substitute, because rows are never back-filled; left out when null, so both read alike.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $model = null,
    ) {
    }
}
