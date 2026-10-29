<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

final readonly class PathGlob
{
    public function __construct(private string $glob)
    {
    }

    public function matches(string $path): bool
    {
        return 1 === preg_match($this->pattern(), $path);
    }

    private function pattern(): string
    {
        // Anchored on the whole path; "**" crosses folders while "*" and "?" stop at a slash, so "src/*" does not reach "src/a/b.php".
        $parts = array_map(
            static fn (string $part): string => str_replace(['\*', '\?'], ['[^/]*', '[^/]'], preg_quote($part, '#')),
            explode('**', $this->glob),
        );

        return '#\A'.implode('.*', $parts).'\z#u';
    }
}
