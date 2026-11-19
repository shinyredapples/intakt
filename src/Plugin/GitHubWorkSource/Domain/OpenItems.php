<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// What one complete listing tick knows: every open item and every failure of the project, open or closed, because the rules plan over both.
final readonly class OpenItems
{
    /**
     * @param list<QueuedIssue>  $items
     * @param array<int, string> $failures
     */
    public function __construct(
        public array $items,
        public array $failures,
    ) {
    }
}
