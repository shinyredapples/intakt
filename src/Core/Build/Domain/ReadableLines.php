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
        private TextPlace $place,
    ) {
    }

    /**
     * @param list<string> $lines
     */
    public static function of(array $lines): self
    {
        $read = new self([], null, [], TextPlace::Apart);

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

        return new self($this->headings + [$first => [$level, trim(implode("\n", array_map(trim(...), $this->paragraph)))]], null, [], TextPlace::Apart);
    }

    private function opened(int $number, string $line): self
    {
        $fence = $this->opening($line);

        if (null !== $fence) {
            return new self($this->headings, $fence, [], TextPlace::Apart);
        }

        $heading = $this->headingOf($line);

        if (null !== $heading) {
            return new self($this->headings + [$number => $heading], null, [], TextPlace::Apart);
        }

        if ($this->isItem($line)) {
            return new self($this->headings, null, [], TextPlace::BelowItem);
        }

        $paragraph = $this->paragraphAfter($this->paragraph, $number, $line);

        return new self($this->headings, null, $paragraph, [] === $paragraph ? TextPlace::Apart : $this->place);
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

    // A list item line starts no paragraph, because CommonMark reads a `---` below it as a thematic break, not as an underline.
    private function isItem(string $line): bool
    {
        if ($this->isBreak($line)) {
            return false;
        }

        // A paragraph that started directly below a list item line is that item's wrapped text, where the next item may carry any number and may be empty.
        if ([] === $this->paragraph || TextPlace::BelowItem === $this->place) {
            return 1 === preg_match('/^ {0,3}(?:[-+*]|\d{1,9}[.)])(?:[ \t]|$)/', $line);
        }

        // An item interrupts a paragraph of its own only when it is not empty and, if ordered, starts at 1, because CommonMark takes the start from the digits' value: `01.` starts at 1, while `2.` and an empty `1.` stay paragraph text.
        return 1 === preg_match('/^ {0,3}(?:[-+*]|0{0,8}1[.)])[ \t]+\S/', $line);
    }

    // A thematic break starts no paragraph, because CommonMark reads a `---` below it as another thematic break, not as an underline.
    private function isBreak(string $line): bool
    {
        return 1 === preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line);
    }

    /**
     * @param array<int, string> $paragraph
     *
     * @return array<int, string>
     */
    private function paragraphAfter(array $paragraph, int $number, string $line): array
    {
        if ('' === trim($line) || $this->isBreak($line)) {
            return [];
        }

        // Up to three spaces and a tab start indented code like four spaces, because a tab moves to the next multiple of four columns.
        if ([] === $paragraph && 1 === preg_match('/^(?: {0,3}\t| {4})/', $line)) {
            return [];
        }

        return $paragraph + [$number => $line];
    }
}
