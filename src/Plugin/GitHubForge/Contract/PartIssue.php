<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The marker travels apart from the body, because it alone finds the part after a create whose answer was lost; the moment bounds that search to what was filed since the plan.
#[Exclude]
final readonly class PartIssue
{
    public function __construct(
        public string $title,
        public string $body,
        public string $marker,
        public IssueLabels $labels,
        public DateTimeImmutable $plannedAt,
    ) {
    }
}
