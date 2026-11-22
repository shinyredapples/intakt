<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a `#` starts a comment only at the start of a word in code, the lexer keeps the open quotes, substitutions and pending here-documents of one source.
final class ShellLexer
{
    private const int CODE = 0;
    private const int DOUBLE_QUOTED = 1;
    private const int PARAMETER = 2;
    private const int BACKTICKS = 3;
    private const array HOLDS_CODE = [self::CODE, self::BACKTICKS];
    // Because these are the shell's metacharacters, a word starts right after any of them.
    private const string WORD_BREAKS = " \t\n;&|()<>";
    private const string SPECIAL_IN_CODE = " \t\n;&|()<>#'\"`\\$";
    private const string SPECIAL_IN_DOUBLE_QUOTES = "\"`\\$";
    private const string SPECIAL_IN_PARAMETER = "}'\"`\\$";

    private readonly int $length;
    private bool $wordStarts = true;

    /**
     * @var list<int>
     */
    private array $frames = [];

    /**
     * @var list<array{delimiter: string, tabsStripped: bool}>
     */
    private array $hereDocuments = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return list<array{int, string}>
     */
    public function comments(): array
    {
        $offset = 0;

        while ($offset < $this->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    private function afterToken(int $offset): int
    {
        return match (array_last($this->frames)) {
            self::DOUBLE_QUOTED => $this->afterDoubleQuotedText($offset),
            self::PARAMETER => $this->afterParameterText($offset),
            default => $this->afterCode($offset),
        };
    }

    private function afterDoubleQuotedText(int $offset): int
    {
        $offset += strcspn($this->source, self::SPECIAL_IN_DOUBLE_QUOTES, $offset);

        return match ($this->source[$offset] ?? '') {
            '' => $this->length,
            '"' => $this->closed($offset + 1),
            '\\' => min($offset + 2, $this->length),
            '$' => $this->afterDollarInText($offset),
            default => $this->open(self::BACKTICKS, $offset + 1),
        };
    }

    private function closed(int $end): int
    {
        array_pop($this->frames);
        $this->wordStarts = false;

        return $end;
    }

    // Because in quoted text and in a `${ }` only a substitution opens code, `$'` there is no quoted string.
    private function afterDollarInText(int $offset): int
    {
        return match ($this->source[$offset + 1] ?? '') {
            '(' => $this->open(self::CODE, $offset + 2),
            '{' => $this->open(self::PARAMETER, $offset + 2),
            default => $offset + 1,
        };
    }

    private function open(int $frame, int $end): int
    {
        $this->frames[] = $frame;
        $this->wordStarts = in_array($frame, self::HOLDS_CODE, true);

        return $end;
    }

    // Because a single quote inside a double-quoted `${ }` is a plain character, only an unquoted `${ }` reads it as a string.
    private function afterParameterText(int $offset): int
    {
        $offset += strcspn($this->source, self::SPECIAL_IN_PARAMETER, $offset);

        return match ($this->source[$offset] ?? '') {
            '' => $this->length,
            '}' => $this->closed($offset + 1),
            "'" => self::DOUBLE_QUOTED === ($this->frames[count($this->frames) - 2] ?? null) ? $offset + 1 : $this->afterQuote("'", $offset + 1),
            '"' => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '\\' => min($offset + 2, $this->length),
            '$' => $this->afterDollarInText($offset),
            default => $this->open(self::BACKTICKS, $offset + 1),
        };
    }

    private function afterCode(int $offset): int
    {
        $plain = strcspn($this->source, self::SPECIAL_IN_CODE, $offset);

        if (0 < $plain) {
            return $this->afterWordPart($offset + $plain);
        }

        $char = $this->source[$offset];

        return match (true) {
            '#' === $char && $this->wordStarts => $this->afterComment($offset),
            "\n" === $char => $this->afterLineEnd($offset + 1),
            '<' === $char && '<' === ($this->source[$offset + 1] ?? '') => $this->afterDoubleLessThan($offset + 2),
            '(' === $char => $this->open(self::CODE, $offset + 1),
            ')' === $char => $this->afterClosingParenthesis($offset + 1),
            str_contains(self::WORD_BREAKS, $char) => $this->startingAWord($offset + 1),
            "'" === $char => $this->afterWordPart($this->afterQuote("'", $offset + 1)),
            '"' === $char => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '`' === $char => $this->afterBacktick($offset + 1),
            '\\' === $char => $this->afterEscape($offset),
            '$' === $char => $this->afterDollar($offset),
            default => $this->afterWordPart($offset + 1),
        };
    }

    private function afterWordPart(int $end): int
    {
        $this->wordStarts = false;

        return $end;
    }

    // Because the shell reads the text between backticks before it parses it, a comment there ends at the closing backtick.
    private function afterComment(int $offset): int
    {
        $ends = self::BACKTICKS === array_last($this->frames) ? "\n`" : "\n";
        $end = $offset + strcspn($this->source, $ends, $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];

        return $end;
    }

    // Because a here-document's body starts on the line after its operator, the bodies are skipped once that line ends.
    private function afterLineEnd(int $offset): int
    {
        $this->wordStarts = true;

        foreach ($this->hereDocuments as $hereDocument) {
            $offset = $this->afterHereDocumentBody($offset, $hereDocument);
        }

        $this->hereDocuments = [];

        return $offset;
    }

    /**
     * @param array{delimiter: string, tabsStripped: bool} $hereDocument
     */
    private function afterHereDocumentBody(int $offset, array $hereDocument): int
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

    private function afterDoubleLessThan(int $offset): int
    {
        if ('<' === ($this->source[$offset] ?? '')) {
            return $this->startingAWord($offset + 1);
        }

        $tabsStripped = '-' === ($this->source[$offset] ?? '');
        $offset += (int) $tabsStripped;
        $offset += strspn($this->source, " \t", $offset);
        [$delimiter, $offset] = $this->delimiterAt($offset);

        if ('' !== $delimiter) {
            $this->hereDocuments[] = ['delimiter' => $delimiter, 'tabsStripped' => $tabsStripped];
        }

        return $this->afterWordPart($offset);
    }

    // Because quoting any part of the delimiter only keeps the body from being expanded, the delimiter line is matched without its quotes.
    /**
     * @return array{string, int}
     */
    private function delimiterAt(int $offset): array
    {
        $delimiter = '';

        while ($offset < $this->length && !str_contains(self::WORD_BREAKS, $this->source[$offset])) {
            $char = $this->source[$offset];
            $end = match ($char) {
                "'", '"' => $this->afterQuote($char, $offset + 1),
                '\\' => min($offset + 2, $this->length),
                default => $offset + 1,
            };
            $delimiter .= str_replace(['"', "'", '\\'], '', substr($this->source, $offset, $end - $offset));
            $offset = $end;
        }

        return [$delimiter, $offset];
    }

    private function afterQuote(string $quote, int $offset): int
    {
        $close = strpos($this->source, $quote, $offset);

        return false === $close ? $this->length : $close + 1;
    }

    // Because a `)` outside any parenthesis ends a `case` pattern, it closes nothing.
    private function afterClosingParenthesis(int $end): int
    {
        if (self::CODE === array_last($this->frames)) {
            array_pop($this->frames);
        }

        return $this->startingAWord($end);
    }

    private function startingAWord(int $end): int
    {
        $this->wordStarts = true;

        return $end;
    }

    private function afterBacktick(int $end): int
    {
        return self::BACKTICKS === array_last($this->frames) ? $this->closed($end) : $this->open(self::BACKTICKS, $end);
    }

    // Because a backslash before a line feed joins the lines, the word that was starting still starts.
    private function afterEscape(int $offset): int
    {
        if ("\n" === ($this->source[$offset + 1] ?? '')) {
            return $offset + 2;
        }

        return $this->afterWordPart(min($offset + 2, $this->length));
    }

    private function afterDollar(int $offset): int
    {
        return match ($this->source[$offset + 1] ?? '') {
            '(' => $this->open(self::CODE, $offset + 2),
            '{' => $this->open(self::PARAMETER, $offset + 2),
            "'" => $this->afterWordPart($this->afterAnsiCString($offset + 2)),
            default => $this->afterWordPart($offset + 1),
        };
    }

    private function afterAnsiCString(int $offset): int
    {
        while (($offset += strcspn($this->source, "'\\", $offset)) < $this->length) {
            if ("'" === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset += 2;
        }

        return $this->length;
    }
}
