<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Labels carry who applied them last, earliest first, because a steering label counts only by the account that applied it; the body and the labels the item holds come from the same read, because the update time has whole seconds and only they show which text and labels the history is of.
#[Exclude]
final readonly class IssueHistory
{
    /**
     * @param list<LabelApplication> $labels
     * @param list<string>           $currentLabels
     */
    public function __construct(
        public string $lastEditor,
        public array $labels,
        public string $body,
        public array $currentLabels,
    ) {
    }
}
