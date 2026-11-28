<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

// What GitHub answered to a read: the body, and whether its Link header names a next page.
final readonly class ReadAnswer
{
    public function __construct(
        public string $body,
        public bool $hasNextPage,
    ) {
    }
}
