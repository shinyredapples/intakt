<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Only code fences, indented code, thematic breaks, list item lines and ATX and setext headings are read, because they are what decides where a heading is; other Markdown is left alone.
final readonly class ReadableLines
{
    /**
     * @param array<int, array{int, string}> $headings
     * @param array<int, string>             $paragraph
     */
    private function __construct(
        public array $headings,
        public ?string $openFence,
        private array $paragraph,
    ) {
    }

    /**
     * @param list<string> $lines
     */
    public static function of(array $lines): self
    {
        $read = new self([], null, []);

        foreach ($lines as $number => $line) {
            $read = $read->after($number, $line);
        }

        return $read;
    }

    private function after(int $number, string $line): self
    {
        if (null !== $this->openFence) {
            return $this->closes($line, $this->openFence) ? clone($this, ['openFence' => null]) : $this;
        }

        $first = array_key_first($this->paragraph);

        return null !== $first && $this->isUnderline($line) ? $this->underlined($first, $line) : $this->opened($number, $line);
    }

    // A fence closes only on the same character repeated at least as often as the opening line, because any other line is still code.
    private function closes(string $line, string $fence): bool
    {
        return 1 === preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'+)[ \t]*$/', $line, $match) && strlen($match[1]) >= strlen($fence);
    }

    private function isUnderline(string $line): bool
    {
        return 1 === preg_match('/^ {0,3}(?:=+|-+)[ \t]*$/', $line);
    }

    // The heading starts at the paragraph's first line, because a section cut before it must not leave the heading's text behind.
    private function underlined(int $first, string $line): self
    {
        $level = '=' === ltrim($line)[0] ? 1 : 2;

        return new self($this->headings + [$first => [$level, trim(implode("\n", array_map(trim(...), $this->paragraph)))]], null, []);
    }

    private function opened(int $number, string $line): self
    {
        $fence = $this->opening($line);

        if (null !== $fence) {
            return new self($this->headings, $fence, []);
        }

        $heading = $this->headingOf($line);

        if (null !== $heading) {
            return new self($this->headings + [$number => $heading], null, []);
        }

        return new self($this->headings, null, $this->paragraphAfter($this->paragraph, $number, $line));
    }

    private function opening(string $line): ?string
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
    private function headingOf(string $line): ?array
    {
        return 1 === preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $heading) ? [strlen($heading[1]), $heading[2] ?? ''] : null;
    }

    /**
     * @param array<int, string> $paragraph
     *
     * @return array<int, string>
     */
    private function paragraphAfter(array $paragraph, int $number, string $line): array
    {
        if ('' === trim($line) || $this->endsParagraph($line, $paragraph)) {
            return [];
        }

        // Up to three spaces and a tab start indented code like four spaces, because a tab moves to the next multiple of four columns.
        if ([] === $paragraph && 1 === preg_match('/^(?: {0,3}\t| {4})/', $line)) {
            return [];
        }

        return $paragraph + [$number => $line];
    }

    // A list item line and a thematic break start no paragraph, because CommonMark reads a `---` below them as a thematic break, not as an underline.
    /**
     * @param array<int, string> $paragraph
     */
    private function endsParagraph(string $line, array $paragraph): bool
    {
        // An ordered item can interrupt a paragraph only when it starts at 1, so `2. text` inside a paragraph stays paragraph text.
        $ordered = [] !== $paragraph ? '1[.)]' : '\d{1,9}[.)]';

        return 1 === preg_match('/^ {0,3}(?:([-*_])(?:[ \t]*\1){2,}[ \t]*$|(?:[-+*]|'.$ordered.')(?:[ \t]|$))/', $line);
    }
}
