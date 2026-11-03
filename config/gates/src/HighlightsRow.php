<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsRow
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public int $line,
        public string $section,
        public ?string $status,
        public array $paths,
    ) {
    }

    public function names(string $folder): bool
    {
        return [] !== array_filter($this->paths, static fn (string $path): bool => $path === $folder || str_starts_with($path, $folder.'/'));
    }
}
