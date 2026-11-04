<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

final readonly class ShadowRoundHeld
{
    public function __construct(
        public string $project,
        public string $change,
        public string $provider,
        public string $reason,
    ) {
    }
}
