<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Who last edited an item and who last applied each of its labels, because only text and labels from accounts with write access steer the loop.
#[Exclude]
final readonly class IssueHistory
{
    /**
     * @param array<string, ?string> $labelAppliers the account that last applied each label, null for a deleted one, in the order of those applications
     */
    public function __construct(
        public string $editor,
        public array $labelAppliers,
    ) {
    }
}
