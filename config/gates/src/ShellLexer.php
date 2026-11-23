<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a `#` starts a comment only at the start of a word in code, the lexer keeps the open quotes, substitutions, `case` commands and pending here-documents of one source.
final class ShellLexer
{
    private const int SUBSHELL = 0;
    private const int DOUBLE_QUOTED = 1;
    private const int PARAMETER = 2;
    private const int BACKTICKS = 3;
    private const int SUBSTITUTION = 4;
    private const int ARITHMETIC = 5;
    private const int ARITHMETIC_GROUP = 6;
    private const int CASE = 7;
    private const array HOLDS_CODE = [self::SUBSHELL, self::BACKTICKS, self::SUBSTITUTION, self::CASE];
    // Because these are the shell's metacharacters, a word starts right after any of them.
    private const string WORD_BREAKS = " \t\n;&|()<>";
    private const string STARTS_A_COMMAND = ';&|';
    // Because a command follows these reserved words, a `case` or `((` right after them opens one.
    private const array LEADS_A_COMMAND = ['!', '{', 'do', 'elif', 'else', 'if', 'then', 'time', 'until', 'while'];
    private const string SPECIAL_IN_CODE = " \t\n;&|()<>#'\"`\\$";
    private const string SPECIAL_IN_DOUBLE_QUOTES = '"`\$';
    private const string SPECIAL_IN_PARAMETER = "}'\"`\\$";
    private const string SPECIAL_IN_ARITHMETIC = '()"`\$';

    private readonly int $length;
    private bool $wordStarts = true;
    private bool $commandStarts = true;

    /**
     * @var list<int>
     */
    private array $frames = [];

    /**
     * @var list<array{delimiter: string, tabsStripped: bool, nesting: int}>
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
            self::ARITHMETIC, self::ARITHMETIC_GROUP => $this->afterArithmeticText($offset),
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
        $this->commandStarts = false;

        return $end;
    }

    // Because in quoted text, in a `${ }` and in arithmetic only a substitution opens code, `$'` there is no quoted string.
    private function afterDollarInText(int $offset): int
    {
        return match ($this->source[$offset + 1] ?? '') {
            '(' => $this->afterDollarParenthesis($offset),
            '{' => $this->open(self::PARAMETER, $offset + 2),
            default => $offset + 1,
        };
    }

    // Because `$((` opens arithmetic, a `<<` in it is a shift and no here-document.
    private function afterDollarParenthesis(int $offset): int
    {
        if ('(' === ($this->source[$offset + 2] ?? '')) {
            return $this->open(self::ARITHMETIC, $offset + 3);
        }

        return $this->open(self::SUBSTITUTION, $offset + 2);
    }

    private function open(int $frame, int $end): int
    {
        $this->frames[] = $frame;
        $this->wordStarts = in_array($frame, self::HOLDS_CODE, true);
        $this->commandStarts = $this->wordStarts;

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

    private function afterArithmeticText(int $offset): int
    {
        $offset += strcspn($this->source, self::SPECIAL_IN_ARITHMETIC, $offset);

        return match ($this->source[$offset] ?? '') {
            '' => $this->length,
            '(' => $this->open(self::ARITHMETIC_GROUP, $offset + 1),
            ')' => $this->afterArithmeticParenthesis($offset),
            '"' => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '\\' => min($offset + 2, $this->length),
            '$' => $this->afterDollarInText($offset),
            default => $this->open(self::BACKTICKS, $offset + 1),
        };
    }

    private function afterArithmeticParenthesis(int $offset): int
    {
        if (self::ARITHMETIC_GROUP === array_last($this->frames) || ')' !== ($this->source[$offset + 1] ?? '')) {
            return $this->closed($offset + 1);
        }

        return $this->closed($offset + 2);
    }

    private function afterCode(int $offset): int
    {
        $plain = strcspn($this->source, self::SPECIAL_IN_CODE, $offset);

        if (0 < $plain) {
            return $this->afterPlainWordPart($offset, $offset + $plain);
        }

        $char = $this->source[$offset];

        return match (true) {
            '#' === $char && $this->wordStarts => $this->afterComment($offset),
            "\n" === $char => $this->afterLineEnd($offset + 1),
            '<' === $char && '<' === ($this->source[$offset + 1] ?? '') => $this->afterDoubleLessThan($offset + 2),
            '(' === $char => $this->afterOpeningParenthesis($offset),
            ')' === $char => $this->afterClosingParenthesis($offset + 1),
            str_contains(self::STARTS_A_COMMAND, $char) => $this->startingACommand($offset + 1),
            str_contains(self::WORD_BREAKS, $char) => $this->startingAWord($offset + 1),
            "'" === $char => $this->afterWordPart($this->afterQuote("'", $offset + 1)),
            '"' === $char => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '`' === $char => $this->afterBacktick($offset + 1),
            '\\' === $char => $this->afterEscape($offset),
            '$' === $char => $this->afterDollar($offset),
            default => $this->afterWordPart($offset + 1),
        };
    }

    // Because a `case` pattern ends at a `)` that closes nothing, the lexer follows the `case` and `esac` words that stand as a command.
    private function afterPlainWordPart(int $offset, int $end): int
    {
        if (!$this->wordStarts || !$this->commandStarts || !$this->endsAWord($end)) {
            return $this->afterWordPart($end);
        }

        $word = substr($this->source, $offset, $end - $offset);

        if ('case' === $word) {
            $this->frames[] = self::CASE;
        } elseif ('esac' === $word && self::CASE === array_last($this->frames)) {
            array_pop($this->frames);
        } elseif (in_array($word, self::LEADS_A_COMMAND, true)) {
            $this->wordStarts = false;

            return $end;
        }

        return $this->afterWordPart($end);
    }

    private function endsAWord(int $end): bool
    {
        return $end >= $this->length || str_contains(self::WORD_BREAKS, $this->source[$end]);
    }

    private function afterWordPart(int $end): int
    {
        $this->wordStarts = false;
        $this->commandStarts = false;

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

    // Because a here-document's body starts on the line after the command that holds its operator, a line feed inside a substitution of that command leaves it pending.
    private function afterLineEnd(int $offset): int
    {
        $this->wordStarts = true;
        $this->commandStarts = true;
        $nesting = $this->nesting();
        $pending = [];

        foreach ($this->hereDocuments as $hereDocument) {
            if ($hereDocument['nesting'] < $nesting) {
                $pending[] = $hereDocument;

                continue;
            }

            $offset = $this->afterHereDocumentBody($offset, $hereDocument);
        }

        $this->hereDocuments = $pending;

        return $offset;
    }

    // Because a `case` command is no substitution, its frame does not hold back a here-document body.
    private function nesting(): int
    {
        return count(array_filter($this->frames, static fn (int $frame): bool => self::CASE !== $frame));
    }

    /**
     * @param array{delimiter: string, tabsStripped: bool, nesting: int} $hereDocument
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
        $start = $offset + strspn($this->source, " \t", $offset);
        [$delimiter, $offset] = $this->delimiterAt($start);

        if ($start < $offset) {
            $this->hereDocuments[] = ['delimiter' => $delimiter, 'tabsStripped' => $tabsStripped, 'nesting' => $this->nesting()];
        }

        return $this->afterWordPart($offset);
    }

    // Because quoting any part of the delimiter only keeps the body from being expanded, the delimiter line is matched after quote removal.
    /**
     * @return array{string, int}
     */
    private function delimiterAt(int $offset): array
    {
        $delimiter = '';

        while ($offset < $this->length && !str_contains(self::WORD_BREAKS, $this->source[$offset])) {
            $char = $this->source[$offset];
            $end = match ($char) {
                "'" => $this->afterQuote("'", $offset + 1),
                '"' => $this->afterEscapedQuote('"', $offset + 1),
                '\\' => min($offset + 2, $this->length),
                default => $offset + 1,
            };
            $delimiter .= $this->withoutQuotes(substr($this->source, $offset, $end - $offset));
            $offset = $end;
        }

        return [$delimiter, $offset];
    }

    private function afterQuote(string $quote, int $offset): int
    {
        $close = strpos($this->source, $quote, $offset);

        return false === $close ? $this->length : $close + 1;
    }

    private function afterEscapedQuote(string $quote, int $offset): int
    {
        while (($offset += strcspn($this->source, $quote.'\\', $offset)) < $this->length) {
            if ($quote === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset += 2;
        }

        return $this->length;
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

    // Because `((` where a command starts opens arithmetic, a `<<` in it is a shift and no here-document.
    private function afterOpeningParenthesis(int $offset): int
    {
        if ($this->commandStarts && '(' === ($this->source[$offset + 1] ?? '')) {
            return $this->open(self::ARITHMETIC, $offset + 2);
        }

        return $this->open(self::SUBSHELL, $offset + 1);
    }

    // Because a `$( )` is part of the word around it, a `#` right after it is text, while a `)` that closes nothing, as in a `case` pattern, starts a command.
    private function afterClosingParenthesis(int $end): int
    {
        return match (array_last($this->frames)) {
            self::SUBSTITUTION => $this->closed($end),
            self::SUBSHELL => $this->startingAWord($this->closed($end)),
            default => $this->startingACommand($end),
        };
    }

    private function startingACommand(int $end): int
    {
        $this->commandStarts = true;

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
            '(' => $this->afterDollarParenthesis($offset),
            '{' => $this->open(self::PARAMETER, $offset + 2),
            "'" => $this->afterWordPart($this->afterEscapedQuote("'", $offset + 2)),
            default => $this->afterWordPart($offset + 1),
        };
    }
}
