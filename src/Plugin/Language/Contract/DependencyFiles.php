<?php

declare(strict_types=1);

namespace Intakt\Plugin\Language\Contract;

// Keyed by path, and a file the revision does not hold is left out, so a missing file and an empty one stay apart.
final readonly class DependencyFiles
{
    /**
     * @param array<string, string> $contents
     */
    public function __construct(
        public array $contents,
    ) {
    }

    public function of(string $path): ?string
    {
        return $this->contents[$path] ?? null;
    }
}
