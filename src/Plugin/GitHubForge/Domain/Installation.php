<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class Installation
{
    /**
     * @param array<string, string> $permissions
     * @param list<string>          $repositories
     */
    public function __construct(
        public array $permissions,
        public array $repositories,
    ) {
    }
}
