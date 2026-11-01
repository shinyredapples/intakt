<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

final readonly class ReviewerIdentity
{
    public function __construct(
        public string $role,
        public string $tier,
    ) {
    }
}
