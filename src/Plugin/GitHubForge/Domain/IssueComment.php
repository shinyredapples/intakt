<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class IssueComment
{
    public function __construct(
        public string $author,
        public bool $byPerson,
        public bool $edited,
        public bool $byThisApp,
        public string $body,
    ) {
    }
}
