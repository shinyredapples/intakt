<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class QuoteCheck
{
    /**
     * @param list<Finding>        $kept
     * @param list<DroppedFinding> $dropped
     */
    public function __construct(
        public array $kept,
        public array $dropped,
    ) {
    }
}
