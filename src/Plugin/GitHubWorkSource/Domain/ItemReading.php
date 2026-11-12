<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Because what one read of an item yields belongs together: the listing's item and what intake derived from it.
#[Exclude]
final readonly class ItemReading
{
    /**
     * @param list<int> $waitsFor
     * @param list<int> $closedBy
     */
    public function __construct(
        public ListedIssue $listed,
        public ?string $editor,
        public bool $trusted,
        public array $waitsFor,
        public array $closedBy,
    ) {
    }
}
