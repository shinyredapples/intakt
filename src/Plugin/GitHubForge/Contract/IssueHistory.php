<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Who acted is kept apart from what the body and labels say, because a steering label counts only by the account that applied it.
#[Exclude]
final readonly class IssueHistory
{
    /**
     * @param list<LabelApplication> $labels the labels the item carries now, the earliest applied first, each with the account that applied it last
     */
    public function __construct(
        public string $lastEditor,
        public array $labels,
    ) {
    }
}
