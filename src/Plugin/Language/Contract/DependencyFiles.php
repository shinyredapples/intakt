<?php

declare(strict_types=1);

namespace Intakt\Plugin\Language\Contract;

final readonly class DependencyFiles
{
    /**
     * @param array<string, string> $contents keyed by the file's path; a file the revision does not hold is left out
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
