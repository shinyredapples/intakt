<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// The lines of one section of a Markdown text, so a parser reads only what that section publishes and never an example in a code block or a commented-out draft.
final readonly class MarkdownSection
{
    // Any heading of level 1 to 3, ATX or Setext, ends the section, because the tables of later sections must never be read as this one.
    private const string LATER_HEADING = '/^ {0,3}#{1,3}(?:[ \t]|$)/';
    private const string ANY_HEADING = '/^ {0,3}#{1,6}(?:[ \t]|$)/';
    private const string SETEXT_UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';
    private const string THEMATIC_BREAK = '/^ {0,3}(?:(?:-[ \t]*){3,}|(?:\*[ \t]*){3,}|(?:_[ \t]*){3,})$/';
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})/';
    private const string CLOSED_COMMENT = '/<!--.*?-->/';
    private const string COMMENT_OPENING = '<!--';

    /**
     * @return list<string>|null
     */
    public static function named(string $text, string $heading): ?array
    {
        $lines = self::published($text);
        $starts = array_keys(array_filter($lines, static fn (string $line): bool => 1 === preg_match($heading, $line)));
        if (1 !== count($starts)) {
            return null;
        }

        $section = [];
        $previous = '';
        foreach (array_slice($lines, $starts[0] + 1) as $line) {
            if (1 === preg_match(self::LATER_HEADING, $line)) {
                break;
            }

            if (self::underlinesAParagraph($line, $previous)) {
                return self::withoutLastParagraph($section);
            }

            $section[] = $line;
            $previous = $line;
        }

        return $section;
    }

    // Fenced and commented-out lines become blank, so neither an example nor a draft supplies the section, its table or its lines.
    /**
     * @return list<string>
     */
    private static function published(string $text): array
    {
        $lines = [];
        $closing = null;
        $carried = '';
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (null !== $closing) {
                $closing = self::stillOpen($closing, $line);
                $lines[] = '';

                continue;
            }

            [$line, $carried] = self::uncommented($carried.$line);
            $closing = self::opening($line);
            $lines[] = null === $closing ? $line : '';
        }

        return $lines;
    }

    // An underline after a heading or a blank line is a thematic break, not a Setext heading, so a nested heading never ends the section.
    private static function underlinesAParagraph(string $line, string $previous): bool
    {
        return 1 === preg_match(self::SETEXT_UNDERLINE, $line)
            && '' !== trim($previous)
            && 1 !== preg_match(self::ANY_HEADING, $previous)
            && 1 !== preg_match(self::THEMATIC_BREAK, $previous);
    }

    // The paragraph above a Setext underline is the next heading's title, so its lines, such as a run date, are not this section's.
    /**
     * @param list<string> $section
     *
     * @return list<string>
     */
    private static function withoutLastParagraph(array $section): array
    {
        while ([] !== $section && '' !== trim(end($section))) {
            array_pop($section);
        }

        return $section;
    }

    private static function stillOpen(string $closing, string $line): ?string
    {
        return 1 === preg_match($closing, $line) ? null : $closing;
    }

    // A comment left open carries its opening to the next line, so every line up to its close stays unpublished.
    /**
     * @return array{string, string}
     */
    private static function uncommented(string $line): array
    {
        $text = (string) preg_replace(self::CLOSED_COMMENT, '', $line);
        $opening = strpos($text, self::COMMENT_OPENING);

        return false === $opening ? [$text, ''] : [substr($text, 0, $opening), self::COMMENT_OPENING];
    }

    private static function opening(string $line): ?string
    {
        return 1 === preg_match(self::FENCE, $line, $fence)
            ? sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($fence[1][0], '/'), strlen($fence[1]))
            : null;
    }
}
