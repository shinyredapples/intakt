<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Contract;

use InvalidArgumentException;

final readonly class LocalRepository
{
    public function __construct(
        public string $path,
        public string $baseBranch,
    ) {
        if ('' === trim($path) || '' === trim($baseBranch)) {
            throw new InvalidArgumentException('A local repository needs a path and a base branch.');
        }
    }
}
