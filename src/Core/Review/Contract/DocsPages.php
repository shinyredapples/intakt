<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The pages a person reads: the only files the Technical writer is given and the ones a change must touch before it can join, label or not.
final readonly class DocsPages
{
    private const array GLOBS = ['docs/**/*.md', 'README.md'];

    public function contains(string $path): bool
    {
        return $this->isCanonical($path) && array_any(self::GLOBS, static fn (string $glob): bool => new PathGlob($glob)->matches($path));
    }

    // Because a path such as "docs/../CONTRIBUTING.md" matches the globs while it names a file outside them, and git never writes one.
    private function isCanonical(string $path): bool
    {
        return !str_contains($path, '\\') && !array_any(explode('/', $path), static fn (string $segment): bool => in_array($segment, ['', '.', '..'], true));
    }
}
