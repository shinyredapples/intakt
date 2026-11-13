<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Labels carry who applied them last, earliest first, because a steering label counts only by the account that applied it.
#[Exclude]
final readonly class IssueHistory
{
    /**
     * @param list<LabelApplication> $labels
     */
    public function __construct(
        public string $lastEditor,
        public array $labels,
    ) {
    }
}
