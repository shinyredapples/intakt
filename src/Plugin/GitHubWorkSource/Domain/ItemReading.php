<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Because what the listing shows of an item belongs together: the item and the numbers its text names.
#[Exclude]
final readonly class ItemReading
{
    /**
     * @param list<int>    $waitsFor
     * @param list<string> $unreadableWaits
     * @param list<int>    $closedBy
     */
    public function __construct(
        public ListedIssue $listed,
        public array $waitsFor,
        public array $unreadableWaits,
        public array $closedBy,
    ) {
    }
}
