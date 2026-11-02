<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Domain\RoundReviewer;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class ReviewerStatus
{
    public function __construct(
        public ReviewerIdentity $reviewer,
        public string $state,
        public ?string $failure,
    ) {
    }

    public static function of(RoundReviewer $reviewer): self
    {
        return new self(new ReviewerIdentity($reviewer->roleName(), $reviewer->tier()->value), $reviewer->state()->value, $reviewer->failure());
    }
}
