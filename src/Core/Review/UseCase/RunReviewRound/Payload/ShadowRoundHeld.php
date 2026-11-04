<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

// The payload of a ShadowRoundHeld row.
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
