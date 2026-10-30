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
        // Git allows any bytes in a file name, and a UTF-8 pattern fails on other bytes, which would let such a path slip past its rule.
        $modifier = mb_check_encoding($path, 'UTF-8') ? 'u' : '';

        return 1 === preg_match($this->pattern().$modifier, '/'.$path);
    }

    private function pattern(): string
    {
        // Anchored; "*" and "?" stop at a slash, "/**/" (a leading "**/" too, by the added slash) may match no folder as in gitignore, "[\s\S]" because git allows a newline in a name.
        return '#\A'.strtr(preg_quote($this->withOneDoubleStarPerRun(), '#'), [
            '/\*\*/' => '/(?:[\s\S]*/)?',
            '\*\*' => '[\s\S]*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
        ]).'\z#';
    }

    private function withOneDoubleStarPerRun(): string
    {
        // Consecutive "**/" segments share a slash that the "/**/" translation would consume, so the second one would demand a folder.
        return (string) preg_replace('#(?:\*\*/)+#', '**/', '/'.$this->glob);
    }
}
