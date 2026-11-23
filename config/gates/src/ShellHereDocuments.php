<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a here-document's body starts on the line after the command that holds its operator, a line feed inside a substitution of that command leaves it pending.
final class ShellHereDocuments
{
    private readonly int $length;

    /**
     * @var list<array{delimiter: string, tabsStripped: bool, nesting: int, quoted: bool}>
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
        $start = $this->afterBlanks($offset);
        [$delimiter, $end, $quoted] = $this->delimiterAt($start);

        if ($start < $end) {
            $this->pending[] = ['delimiter' => $delimiter, 'tabsStripped' => $tabsStripped, 'nesting' => $nesting, 'quoted' => $quoted];
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

    // Because a backslash before a line feed joins the lines, it may stand between the operator and the delimiter.
    private function afterBlanks(int $offset): int
    {
        do {
            $offset += strspn($this->source, " \t", $offset);
            $joined = "\\\n" === substr($this->source, $offset, 2);
            $offset += $joined ? 2 : 0;
        } while ($joined);

        return $offset;
    }

    // Because quoting any part of the delimiter only keeps the body from being expanded, the delimiter line is matched after quote removal.
    /**
     * @return array{string, int, bool}
     */
    private function delimiterAt(int $offset): array
    {
        $delimiter = '';
        $quoted = false;

        while ($offset < $this->length && !str_contains(ShellLexer::WORD_BREAKS, $this->source[$offset])) {
            $end = match ($this->source[$offset]) {
                "'" => $this->quotes->endOfSingleQuoted($offset + 1),
                '"' => $this->quotes->endOfEscaped('"', $offset + 1),
                '\\' => min($offset + 2, $this->length),
                '$' => "'" === ($this->source[$offset + 1] ?? '') ? $this->quotes->endOfEscaped("'", $offset + 2) : $offset + 1,
                default => $offset + 1,
            };
            $part = substr($this->source, $offset, $end - $offset);
            $quoted = $quoted || ("\\\n" !== $part && 1 < strlen($part));
            $delimiter .= $this->withoutQuotes($part);
            $offset = $end;
        }

        return [$delimiter, $offset, $quoted];
    }

    // Because inside double quotes a backslash escapes only `$`, a backtick, `"` and itself, any other backslash stays in the delimiter, while a backslash before a line feed joins the lines in both places, and `$'...'` loses its `$` with its quotes.
    private function withoutQuotes(string $part): string
    {
        return match ($part[0]) {
            "'" => substr($part, 1, -1),
            '"' => (string) preg_replace(['~\\\\\n~', '~\\\\([$`"\\\\])~'], ['', '$1'], substr($part, 1, -1)),
            '\\' => ltrim(substr($part, 1), "\n"),
            '$' => '$' === $part ? $part : substr($part, 2, -1),
            default => $part,
        };
    }

    /**
     * @param array{delimiter: string, tabsStripped: bool, nesting: int, quoted: bool} $hereDocument
     */
    private function afterBody(int $offset, array $hereDocument): int
    {
        while ($offset < $this->length) {
            [$line, $offset] = $this->bodyLineAt($offset, $hereDocument['quoted']);

            if ($hereDocument['delimiter'] === ($hereDocument['tabsStripped'] ? ltrim($line, "\t") : $line)) {
                return min($offset, $this->length);
            }
        }

        return $this->length;
    }

    // Because in the body of an unquoted delimiter a backslash before a line feed joins the lines before the delimiter line is matched, so `prefix\` and `EOF` read as `prefixEOF`.
    /**
     * @return array{string, int}
     */
    private function bodyLineAt(int $offset, bool $quoted): array
    {
        $line = '';

        do {
            $end = $offset + strcspn($this->source, "\n", $offset);
            $part = substr($this->source, $offset, $end - $offset);
            $offset = $end + 1;
            $joins = !$quoted && $end < $this->length && 1 === strspn(strrev($part), '\\') % 2;
            $line .= $joins ? substr($part, 0, -1) : $part;
        } while ($joins);

        return [$line, $offset];
    }
}
