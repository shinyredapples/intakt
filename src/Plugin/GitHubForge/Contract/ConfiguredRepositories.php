<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// Only the name, because how a remote spells that repository is the caller's git detail and not the forge's.
interface ConfiguredRepositories
{
    // The project's `github` entry as "owner/name", or null when the project has none.
    public function nameOf(string $project): ?string;
}
