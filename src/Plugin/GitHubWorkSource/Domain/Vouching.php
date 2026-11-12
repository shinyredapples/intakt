<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Kept with the item, because its history is read again only when the item changes: whether author and last editor had write access, and the labels such an account applied last, in the order of those applications.
#[Exclude]
final readonly class Vouching
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public string $editor,
        public bool $trusted,
        public array $labels,
    ) {
    }
}
