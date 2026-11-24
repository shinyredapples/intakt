<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// How the last round of one change ended, in the words its chip uses, with the round the review page of that change opens.
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
