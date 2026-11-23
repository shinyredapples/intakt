<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a here-document's body starts on the line after the command that holds its operator, a line feed inside a substitution of that command leaves it pending.
final class ShellHereDocuments
{
    private readonly int $length;

    /**
     * @var list<array{delimiter: string, tabsStripped: bool, nesting: int}>
     */
    private array $pending = [];

    public function __construct(private readonly string $source, private readonly ShellQuotes $quotes)
    {
        $this->length = strlen($source);
    }

    public function afterOperator(int $offset, int $nesting): int
    {
        $tabsStripped = '-' === ($this->source[$offset] ?? '');
        $offset += (int) $tabsStripped;
        $start = $offset + strspn($this->source, " \t", $offset);
        [$delimiter, $end] = $this->delimiterAt($start);

        if ($start < $end) {
            $this->pending[] = ['delimiter' => $delimiter, 'tabsStripped' => $tabsStripped, 'nesting' => $nesting];
        }

        return $end;
    }

    public function afterBodies(int $offset, int $nesting): int
    {
        $stillPending = [];

        foreach ($this->pending as $hereDocument) {
            if ($hereDocument['nesting'] < $nesting) {
                $stillPending[] = $hereDocument;

                continue;
            }

            $offset = $this->afterBody($offset, $hereDocument);
        }

        $this->pending = $stillPending;

        return $offset;
    }

    // Because quoting any part of the delimiter only keeps the body from being expanded, the delimiter line is matched after quote removal.
    /**
     * @return array{string, int}
     */
    private function delimiterAt(int $offset): array
    {
        $delimiter = '';

        while ($offset < $this->length && !str_contains(ShellLexer::WORD_BREAKS, $this->source[$offset])) {
            $char = $this->source[$offset];
            $end = match ($char) {
                "'" => $this->quotes->endOfSingleQuoted($offset + 1),
                '"' => $this->quotes->endOfEscaped('"', $offset + 1),
                '\\' => min($offset + 2, $this->length),
                default => $offset + 1,
            };
            $delimiter .= $this->withoutQuotes(substr($this->source, $offset, $end - $offset));
            $offset = $end;
        }

        return [$delimiter, $offset];
    }

    // Because inside double quotes a backslash escapes only `$`, a backtick, `"` and itself, any other backslash stays in the delimiter.
    private function withoutQuotes(string $part): string
    {
        return match ($part[0]) {
            "'" => substr($part, 1, -1),
            '"' => (string) preg_replace('~\\\\([$`"\\\\])~', '$1', substr($part, 1, -1)),
            '\\' => substr($part, 1),
            default => $part,
        };
    }

    /**
     * @param array{delimiter: string, tabsStripped: bool, nesting: int} $hereDocument
     */
    private function afterBody(int $offset, array $hereDocument): int
    {
        while ($offset < $this->length) {
            $end = $offset + strcspn($this->source, "\n", $offset);
            $line = substr($this->source, $offset, $end - $offset);
            $offset = $end + 1;

            if ($hereDocument['delimiter'] === ($hereDocument['tabsStripped'] ? ltrim($line, "\t") : $line)) {
                return min($offset, $this->length);
            }
        }

        return $this->length;
    }
}
