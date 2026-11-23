<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a `#` starts a comment only at the start of a word in code, the lexer keeps the open quotes, substitutions, `case` commands and pending here-documents of one source.
final class ShellLexer
{
    // Because these are the shell's metacharacters, a word starts right after any of them.
    public const string WORD_BREAKS = " \t\n;&|()<>";
    private const int SUBSHELL = 0;
    private const int DOUBLE_QUOTED = 1;
    private const int PARAMETER = 2;
    private const int BACKTICKS = 3;
    private const int SUBSTITUTION = 4;
    private const int ARITHMETIC = 5;
    private const int ARITHMETIC_GROUP = 6;
    private const int CASE_SUBJECT = 7;
    private const int CASE_PATTERN = 8;
    private const int CASE_BODY = 9;
    private const array CASE_FRAMES = [self::CASE_SUBJECT, self::CASE_PATTERN, self::CASE_BODY];
    private const array HOLDS_CODE = [self::SUBSHELL, self::BACKTICKS, self::SUBSTITUTION, ...self::CASE_FRAMES];
    private const string STARTS_A_COMMAND = ';&|';
    // Because a command follows these reserved words, a `case` or `((` right after them opens one.
    private const array LEADS_A_COMMAND = ['!', '{', 'do', 'elif', 'else', 'if', 'then', 'time', 'until', 'while'];
    private const string SPECIAL_IN_CODE = " \t\n;&|()<>#'\"`\\$";
    private const string SPECIAL_IN_DOUBLE_QUOTES = '"`\$';
    private const string SPECIAL_IN_PARAMETER = "}'\"`\\$";
    private const string SPECIAL_IN_ARITHMETIC = '()"`\$';

    private readonly int $length;
    private readonly ShellQuotes $quotes;
    private readonly ShellHereDocuments $hereDocuments;
    private bool $wordStarts = true;
    private bool $commandStarts = true;
    private bool $arithmeticMayStart = false;

    /**
     * @var list<int>
     */
    private array $frames = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
        $this->quotes = new ShellQuotes($source);
        $this->hereDocuments = new ShellHereDocuments($source, $this->quotes);
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
        $this->arithmeticMayStart = false;

        return $end;
    }

    // Because a single quote inside a double-quoted `${ }` is a plain character, only an unquoted `${ }` reads it as a string.
    private function afterParameterText(int $offset): int
    {
        $offset += strcspn($this->source, self::SPECIAL_IN_PARAMETER, $offset);

        return match ($this->source[$offset] ?? '') {
            '' => $this->length,
            '}' => $this->closed($offset + 1),
            "'" => self::DOUBLE_QUOTED === ($this->frames[count($this->frames) - 2] ?? null) ? $offset + 1 : $this->quotes->endOfSingleQuoted($offset + 1),
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
            ')' => $this->closed($offset + $this->closingArithmeticLength($offset)),
            '"' => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '\\' => min($offset + 2, $this->length),
            '$' => $this->afterDollarInText($offset),
            default => $this->open(self::BACKTICKS, $offset + 1),
        };
    }

    private function closingArithmeticLength(int $offset): int
    {
        return self::ARITHMETIC === array_last($this->frames) && ')' === ($this->source[$offset + 1] ?? '') ? 2 : 1;
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
            ';' === $char && $this->endsACaseBranch($offset) => $this->afterCaseBranch($offset),
            '<' === $char && '<' === ($this->source[$offset + 1] ?? '') => $this->afterDoubleLessThan($offset + 2),
            '(' === $char => $this->afterOpeningParenthesis($offset),
            ')' === $char => $this->afterClosingParenthesis($offset + 1),
            str_contains(self::STARTS_A_COMMAND, $char) => $this->startingACommand($offset + 1),
            str_contains(self::WORD_BREAKS, $char) => $this->startingAWord($offset + 1),
            "'" === $char => $this->afterWordPart($this->quotes->endOfSingleQuoted($offset + 1)),
            '"' === $char => $this->open(self::DOUBLE_QUOTED, $offset + 1),
            '`' === $char => $this->afterBacktick($offset + 1),
            '\\' === $char => $this->afterEscape($offset),
            '$' === $char => $this->afterDollar($offset),
            default => $this->afterWordPart($offset + 1),
        };
    }

    // Because a `;;`, `;&` or `;;&` ends a branch of a `case` command, the next word is a pattern again.
    private function endsACaseBranch(int $offset): bool
    {
        return self::CASE_BODY === array_last($this->frames) && str_contains(';&', $this->source[$offset + 1] ?? '');
    }

    private function afterCaseBranch(int $offset): int
    {
        $this->frames[array_key_last($this->frames)] = self::CASE_PATTERN;

        return $this->startingACommand($offset + (str_starts_with(substr($this->source, $offset, 3), ';;&') ? 3 : 2));
    }

    // Because a `case` pattern ends at a `)` that closes nothing, the lexer follows a `case` command from its subject through its patterns and branches to its `esac`.
    private function afterPlainWordPart(int $offset, int $end): int
    {
        $word = $this->wordStarts && $this->endsAWord($end) ? substr($this->source, $offset, $end - $offset) : '';
        $frame = array_last($this->frames);

        return match (true) {
            self::CASE_PATTERN === $frame => $this->afterPatternWord($word, $end),
            'in' === $word && self::CASE_SUBJECT === $frame => $this->afterCaseSubject($end),
            $this->commandStarts => $this->afterCommandWord($word, $frame, $end),
            default => $this->afterWordPart($end),
        };
    }

    private function endsAWord(int $end): bool
    {
        return $end >= $this->length || str_contains(self::WORD_BREAKS, $this->source[$end]);
    }

    private function afterPatternWord(string $word, int $end): int
    {
        if ('esac' === $word) {
            array_pop($this->frames);
        }

        return $this->afterWordPart($end);
    }

    private function afterCaseSubject(int $end): int
    {
        $this->frames[array_key_last($this->frames)] = self::CASE_PATTERN;

        return $this->afterWordPart($end);
    }

    // Because a command follows a reserved word such as `then`, the command start lasts over it, while after `for` a `((` opens arithmetic.
    private function afterCommandWord(string $word, ?int $frame, int $end): int
    {
        match (true) {
            'case' === $word => $this->frames[] = self::CASE_SUBJECT,
            'esac' === $word && self::CASE_BODY === $frame => array_pop($this->frames),
            default => null,
        };

        if (!in_array($word, [...self::LEADS_A_COMMAND, 'for'], true)) {
            return $this->afterWordPart($end);
        }

        $this->wordStarts = false;
        $this->commandStarts = 'for' !== $word;
        $this->arithmeticMayStart = 'for' === $word;

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

    private function afterLineEnd(int $offset): int
    {
        $this->wordStarts = true;
        $this->commandStarts = true;

        return $this->hereDocuments->afterBodies($offset, $this->nesting());
    }

    // Because a `case` command is no substitution, its frame does not hold back a here-document body.
    private function nesting(): int
    {
        return count(array_filter($this->frames, static fn (int $frame): bool => !in_array($frame, self::CASE_FRAMES, true)));
    }

    private function afterDoubleLessThan(int $offset): int
    {
        if ('<' === ($this->source[$offset] ?? '')) {
            return $this->startingAWord($offset + 1);
        }

        return $this->afterWordPart($this->hereDocuments->afterOperator($offset, $this->nesting()));
    }

    // Because `((` where a command starts or after `for` opens arithmetic, a `<<` in it is a shift, a `(` before a pattern opens nothing, and `<(` or `>(` is part of its word.
    private function afterOpeningParenthesis(int $offset): int
    {
        return match (true) {
            self::CASE_PATTERN === array_last($this->frames) => $this->startingAWord($offset + 1),
            ($this->commandStarts || $this->arithmeticMayStart) && '(' === ($this->source[$offset + 1] ?? '') => $this->open(self::ARITHMETIC, $offset + 2),
            0 < $offset && str_contains('<>', $this->source[$offset - 1]) => $this->open(self::SUBSTITUTION, $offset + 1),
            default => $this->open(self::SUBSHELL, $offset + 1),
        };
    }

    // Because a `$( )`, `<( )` or `>( )` is part of the word around it, a `#` right after it is text, while a `)` that closes nothing, as after a `case` pattern, starts a command.
    private function afterClosingParenthesis(int $end): int
    {
        return match (array_last($this->frames)) {
            self::CASE_PATTERN => $this->afterCasePattern($end),
            self::SUBSTITUTION => $this->closed($end),
            self::SUBSHELL => $this->startingAWord($this->closed($end)),
            default => $this->startingACommand($end),
        };
    }

    private function afterCasePattern(int $end): int
    {
        $this->frames[array_key_last($this->frames)] = self::CASE_BODY;

        return $this->startingACommand($end);
    }

    private function startingACommand(int $end): int
    {
        $this->commandStarts = true;
        $this->arithmeticMayStart = false;

        return $this->startingAWord($end);
    }

    private function startingAWord(int $end): int
    {
        $this->wordStarts = true;

        return $end;
    }

    private function afterWordPart(int $end): int
    {
        $this->wordStarts = false;
        $this->commandStarts = false;
        $this->arithmeticMayStart = false;

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
            "'" => $this->afterWordPart($this->quotes->endOfEscaped("'", $offset + 2)),
            default => $this->afterWordPart($offset + 1),
        };
    }
}
