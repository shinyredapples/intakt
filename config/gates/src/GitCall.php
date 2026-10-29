<?php

declare(strict_types=1);

namespace Intakt\Gates;

// The gates keep their own copy of Core/Git/Contract/GitCall, as of HardenedGit, because only a protected path may decide how a gate reads the repository.
final readonly class GitCall
{
    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function __construct(
        public array $command,
        public string $directory,
        public array $environment,
    ) {
    }
}
