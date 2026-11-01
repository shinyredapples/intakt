<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

final readonly class ReviewerStatus
{
    public function __construct(
        public string $role,
        public string $tier,
        public string $state,
        public ?string $failure,
    ) {
    }
}
