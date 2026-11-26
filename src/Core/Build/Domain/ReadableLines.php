<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Only code fences, indented code, thematic breaks, list item lines and ATX and setext headings are read, because they are what decides where a heading is; other Markdown is left alone.
final readonly class ReadableLines
{
    /**
     * @param array<int, array{int, string}> $headings
     */
    private function __construct(
        public array $headings,
        public ?string $openFence,
    ) {
    }

    /**
     * @param list<string> $lines
     */
    public static function of(array $lines): self
    {
        $headings = [];
        $fence = null;
        $paragraph = [];

        foreach ($lines as $number => $line) {
            if (null !== $fence) {
                $fence = self::closes($line, $fence) ? null : $fence;

                continue;
            }

            $level = [] === $paragraph ? null : self::underlineOf($line);

            if (null !== $level) {
                $headings[array_key_first($paragraph)] = [$level, trim(implode("\n", array_map(trim(...), $paragraph)))];
                $paragraph = [];

                continue;
            }

            $fence = self::opening($line);
            $heading = null === $fence ? self::headingOf($line) : null;

            if (null !== $heading) {
                $headings[$number] = $heading;
            }

            $paragraph = null === $fence && null === $heading ? self::paragraphAfter($paragraph, $number, $line) : [];
        }

        return new self($headings, $fence);
    }

    // A fence closes only on the same character repeated at least as often as the opening line, because any other line is still code.
    private static function closes(string $line, string $fence): bool
    {
        return 1 === preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'+)[ \t]*$/', $line, $match) && strlen($match[1]) >= strlen($fence);
    }

    private static function underlineOf(string $line): ?int
    {
        if (1 !== preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $line, $match)) {
            return null;
        }

        return '=' === $match[1][0] ? 1 : 2;
    }

    private static function opening(string $line): ?string
    {
        if (1 !== preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match)) {
            return null;
        }

        // A backtick fence's info string holds no backtick, because such a line is an inline code span.
        return '`' === $match[1][0] && str_contains($match[2], '`') ? null : $match[1];
    }

    /**
     * @return ?array{int, string}
     */
    private static function headingOf(string $line): ?array
    {
        return 1 === preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $heading) ? [strlen($heading[1]), $heading[2] ?? ''] : null;
    }

    /**
     * @param array<int, string> $paragraph
     *
     * @return array<int, string>
     */
    private static function paragraphAfter(array $paragraph, int $number, string $line): array
    {
        if ('' === trim($line) || self::endsParagraph($line)) {
            return [];
        }

        // A tab moves to the next multiple of four columns, so up to three spaces and a tab indent as far as four spaces.
        if ([] === $paragraph && 1 === preg_match('/^(?: {0,3}\t| {4})/', $line)) {
            return [];
        }

        return $paragraph + [$number => $line];
    }

    // A list item's text and a thematic break are no paragraph a later underline could turn into a heading.
    private static function endsParagraph(string $line): bool
    {
        return 1 === preg_match('/^ {0,3}(?:([-*_])(?:[ \t]*\1){2,}[ \t]*$|(?:[-+*]|\d{1,9}[.)])(?:[ \t]|$))/', $line);
    }
}
