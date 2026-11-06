<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

// Because SymfonyStyle wraps a block at the terminal width (120 columns at most) and cuts inside a word, a long path in an error is split across lines; a space at a break is lost, so look only for text without spaces.
final class ConsoleDisplay
{
    public static function unwrapped(string $display): string
    {
        return preg_replace('/[ \t]*\R[ \t]*/', '', $display) ?? $display;
    }
}
