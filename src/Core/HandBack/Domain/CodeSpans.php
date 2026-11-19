<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The spans of one text are read from left to right by one instance, because what a search learned about a paragraph (where it ends, which backtick runs it holds) answers the next search in it, and without that memory a paragraph of unpaired runs is scanned once per run.
final class CodeSpans
{
    private int $paragraphEnd = 0;

    private bool $scannedToTheParagraphEnd = false;

    /**
     * @var array<int, int>
     */
    private array $lastRunOfLength = [];

    public function __construct(
        private readonly string $text,
    ) {
    }

    // A code span closes only with a backtick run of the same length in the same paragraph; a run without one, and a backtick escaped with a backslash, is a literal backtick (https://spec.commonmark.org/0.31.2/#backslash-escapes).
    public function spanOrLiteralAt(int $start, int $offset): string
    {
        $escaped = $this->isEscaped($start, $offset) ? 1 : 0;
        $length = strspn($this->text, '`', $start) - $escaped;
        $close = 0 === $length ? null : $this->closingRunEnd($start + $escaped + $length, $length);

        return substr($this->text, $start, ($close ?? $start + $escaped + $length) - $start);
    }

    // Backslashes before the part read so far may belong to a code span, where they escape nothing.
    private function isEscaped(int $start, int $offset): bool
    {
        $before = substr($this->text, $offset, $start - $offset);

        return 1 === (strlen($before) - strlen(rtrim($before, '\\'))) % 2;
    }

    private function closingRunEnd(int $from, int $length): ?int
    {
        $this->enterTheParagraphAt($from);

        if ($this->scannedToTheParagraphEnd && ($this->lastRunOfLength[$length] ?? -1) < $from) {
            return null;
        }

        $position = strpos($this->text, '`', $from);

        while (false !== $position && $position < $this->paragraphEnd) {
            $run = strspn($this->text, '`', $position);

            if ($run === $length) {
                return $position + $run;
            }

            $this->lastRunOfLength[$run] = $position;
            $position = strpos($this->text, '`', $position + $run);
        }

        $this->scannedToTheParagraphEnd = true;

        return null;
    }

    private function enterTheParagraphAt(int $from): void
    {
        if ($from < $this->paragraphEnd) {
            return;
        }

        $this->paragraphEnd = ParagraphEnd::after($this->text, $from);
        $this->scannedToTheParagraphEnd = false;
        $this->lastRunOfLength = [];
    }
}
