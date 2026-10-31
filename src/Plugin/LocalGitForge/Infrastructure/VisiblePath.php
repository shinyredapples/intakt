<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class VisiblePath
{
    public function of(string $path): string
    {
        // A path may hold ESC or other control bytes that would rewrite the terminal; the backslash is escaped so the visible form stays unambiguous.
        $visible = (string) preg_replace_callback(
            '/[\x00-\x1F\x7F\\\\]|\xC2[\x80-\x9F]/',
            static fn (array $match): string => '\\' === $match[0] ? '\\\\' : implode('', array_map(static fn (string $byte): string => sprintf('\x%02x', ord($byte[0])), str_split($match[0]))),
            $path,
        );

        // Because a path may contain "<info>", which the formatter would swallow.
        return OutputFormatter::escape($visible);
    }
}
