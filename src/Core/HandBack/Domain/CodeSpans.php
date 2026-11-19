<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The backtick runs of a paragraph are listed once, when the reader enters it, because searching the paragraph again for every run that has no partner takes quadratic time on a long text of unpaired backticks.
final readonly class CodeSpans
{
    /**
     * @param array<int, list<int>> $runsOfLength
     */
    private function __construct(
        private string $text,
        private int $paragraphEnd,
        private array $runsOfLength,
    ) {
    }

    public static function of(string $text): self
    {
        return new self($text, 0, []);
    }

    public function inTheParagraphAt(int $start): self
    {
        if ($start < $this->paragraphEnd) {
            return $this;
        }

        $paragraphEnd = ParagraphEnd::after($this->text, $start);
        $runsOfLength = [];
        $position = strpos($this->text, '`', $start);

        while (false !== $position && $position < $paragraphEnd) {
            $run = strspn($this->text, '`', $position);
            $runsOfLength[$run][] = $position;
            $position = strpos($this->text, '`', $position + $run);
        }

        return new self($this->text, $paragraphEnd, $runsOfLength);
    }

    // A code span closes only with a backtick run of the same length in the same paragraph; a run without one, and a backtick escaped with a backslash, is a literal backtick (https://spec.commonmark.org/0.31.2/#backslash-escapes).
    public function spanOrLiteralAt(int $start, int $offset): string
    {
        $escaped = $this->isEscaped($start, $offset) ? 1 : 0;
        $length = strspn($this->text, '`', $start) - $escaped;
        $opened = $start + $escaped + $length;
        $close = $this->firstRunFrom($opened, $length);

        return substr($this->text, $start, (null === $close ? $opened : $close + $length) - $start);
    }

    // Backslashes before the part read so far may belong to a code span, where they escape nothing.
    private function isEscaped(int $start, int $offset): bool
    {
        $before = substr($this->text, $offset, $start - $offset);

        return 1 === (strlen($before) - strlen(rtrim($before, '\\'))) % 2;
    }

    // A binary search, because the positions are in ascending order and a paragraph may hold thousands of runs of one length.
    private function firstRunFrom(int $from, int $length): ?int
    {
        $runs = $this->runsOfLength[$length] ?? [];
        $low = 0;
        $high = count($runs);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            [$low, $high] = $runs[$middle] < $from ? [$middle + 1, $high] : [$low, $middle];
        }

        return $runs[$low] ?? null;
    }
}
