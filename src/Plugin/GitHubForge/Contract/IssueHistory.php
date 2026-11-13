<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Who last edited an item and who last applied each of its labels (null for a deleted account, in the order of those applications), because only text and labels from accounts with write access steer the loop.
#[Exclude]
final readonly class IssueHistory
{
    /**
     * @param array<string, ?string> $labelAppliers
     */
    public function __construct(
        public string $editor,
        public array $labelAppliers,
    ) {
    }
}
