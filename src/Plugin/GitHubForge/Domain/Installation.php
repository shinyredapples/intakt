<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class Installation
{
    /**
     * @param array<string, string> $permissions permission name to level
     * @param list<string>          $repositories full names
     */
    public function __construct(
        public array $permissions,
        public array $repositories,
    ) {
    }
}
