<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// The lines of one section of a Markdown text, so a parser reads only what that section holds and never an example in a code block.
final readonly class MarkdownSection
{
    // Any heading of level 1 to 3, ATX or Setext, ends the section, because the tables of later sections must never be read as this one.
    private const string LATER_HEADING = '/^ {0,3}#{1,3}(?:[ \t]|$)/';
    private const string SETEXT_UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})/';

    /**
     * @return list<string>|null
     */
    public static function named(string $text, string $heading): ?array
    {
        $lines = self::outsideFences($text);
        $starts = array_keys(array_filter($lines, static fn (string $line): bool => 1 === preg_match($heading, $line)));
        if (1 !== count($starts)) {
            return null;
        }

        $section = [];
        $previous = '';
        foreach (array_slice($lines, $starts[0] + 1) as $line) {
            if (self::isHeading($line, $previous)) {
                break;
            }

            $section[] = $line;
            $previous = $line;
        }

        return $section;
    }

    private static function isHeading(string $line, string $previous): bool
    {
        return 1 === preg_match(self::LATER_HEADING, $line)
            || ('' !== trim($previous) && 1 === preg_match(self::SETEXT_UNDERLINE, $line));
    }

    // Fenced lines become blank, so an example in a code block never supplies the section, its table or its lines.
    /**
     * @return list<string>
     */
    private static function outsideFences(string $text): array
    {
        $lines = [];
        $closing = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $inFence = null !== $closing;
            $closing = $inFence ? self::stillOpen($closing, $line) : self::opening($line);
            $lines[] = $inFence || null !== $closing ? '' : $line;
        }

        return $lines;
    }

    private static function opening(string $line): ?string
    {
        return 1 === preg_match(self::FENCE, $line, $fence)
            ? sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($fence[1][0], '/'), strlen($fence[1]))
            : null;
    }

    private static function stillOpen(string $closing, string $line): ?string
    {
        return 1 === preg_match($closing, $line) ? null : $closing;
    }
}
