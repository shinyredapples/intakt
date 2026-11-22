<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// Only the name, because how a remote spells that repository is the caller's git detail and not the forge's.
interface ConfiguredRepositories
{
    // Null instead of a failure, because a project without a forge is a valid setup (a local remote).
    public function nameOf(string $project): ?string;
}
