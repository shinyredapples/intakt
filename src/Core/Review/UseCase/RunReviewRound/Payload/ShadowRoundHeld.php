<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

use Intakt\Core\Ai\Contract\Provider;

final readonly class ShadowRoundHeld
{
    public function __construct(
        public string $project,
        public string $change,
        public Provider $provider,
        public string $reason,
    ) {
    }
}
