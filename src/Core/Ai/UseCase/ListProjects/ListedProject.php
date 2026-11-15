<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ListProjects;

use Intakt\Core\Review\Contract\ReplayMark;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class ListedProject
{
    public function __construct(
        public string $name,
        public ReplayMark $mark,
    ) {
    }
}
