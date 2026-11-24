<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Holds the round too, because a card links the review page that round opens.
#[Exclude]
final readonly class ProjectOutcome
{
    public function __construct(
        public string $change,
        public string $words,
        public string $round,
    ) {
    }
}
