<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What an item's history said when it was last read: whether its author and last editor had write access, and which labels such an account applied last.
#[Exclude]
final readonly class Vouching
{
    /**
     * @param list<string> $labels in the order of their last application
     */
    public function __construct(
        public string $editor,
        public bool $trusted,
        public array $labels,
    ) {
    }
}
