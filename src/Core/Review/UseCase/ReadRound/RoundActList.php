<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class RoundActList
{
    /**
     * @param list<Finding> $act
     */
    public function __construct(
        public string $roundId,
        public bool $judged,
        public array $act,
    ) {
    }
}
