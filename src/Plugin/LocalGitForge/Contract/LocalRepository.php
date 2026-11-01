<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Contract;

final readonly class LocalRepository
{
    public function __construct(
        public string $path,
        public string $baseBranch,
    ) {
        if ('' === trim($path) || '' === trim($baseBranch)) {
            throw new LocalRepositoryIncomplete('A local repository needs a path and a base branch.');
        }
    }

    // Checked before git runs, because git's own message for a missing directory does not name the path the person gave.
    public static function existingAt(string $path, string $baseBranch): self
    {
        return is_dir($path) ? new self($path, $baseBranch) : throw RepositoryNotFound::at($path);
    }
}
