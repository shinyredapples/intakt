<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class ReviewerIdentity
{
    public function __construct(
        public string $role,
        public string $tier,
    ) {
    }
}
