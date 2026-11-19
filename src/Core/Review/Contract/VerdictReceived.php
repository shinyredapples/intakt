<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final readonly class VerdictReceived
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public Tier $tier,
        public Decision $decision,
        public int $findings,
        public ProjectMode $mode,
        // Why a blocked verdict blocks, so the dashboard names it; left out when null (any other verdict), so such a row reads like one written before reasons were recorded.
        #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $reason = null,
    ) {
    }
}
