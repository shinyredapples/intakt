<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

// Because SymfonyStyle wraps a block at the terminal width (120 columns at most, so no COLUMNS keeps a long path whole), cuts inside a word and drops a space it breaks at, a display is matched against a pattern that allows a break between any two characters instead of being joined back.
final class ConsoleDisplay
{
    private const string BREAK = '[ \t]*\R[ \t]*';

    public static function wrapped(string $text): string
    {
        $characters = array_map(
            static fn (string $character): string => ' ' === $character ? '(?: |'.self::BREAK.')' : preg_quote($character, '/'),
            mb_str_split($text),
        );

        return '/'.implode('(?:'.self::BREAK.')?', $characters).'/u';
    }
}
