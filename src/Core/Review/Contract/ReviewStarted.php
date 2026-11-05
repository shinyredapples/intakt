<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final readonly class ReviewStarted
{
    public function __construct(
        public string $round,
        public string $project,
        public int $reviewers,
        public string $change,
        public int $number,
        public string $task,
        // Left out when null, because a start without a source must not claim a repository or base.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $repository = null,
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $base = null,
    ) {
    }
}
