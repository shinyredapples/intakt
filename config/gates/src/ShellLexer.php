<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a `#` starts a comment depends on the quotes, expansions, substitutions and here-documents open around it, the lexer keeps them on a stack for one source.
final class ShellLexer
{
    private const int DOUBLE_QUOTES = 0;
    private const int EXPANSION = 1;
    // Because the operator after a subscript decides how a quoted expansion reads a `'`, the expansion waits in this frame until its subscript closes.
    private const int QUOTED_HEAD = 2;
    // Because a pattern of `#`, `%` or `/` is quoted on its own even inside double quotes, a `'` in it opens a string.
    private const int QUOTED_PATTERN = 3;
    // Because bash reads a `'` in any other part of a `${ }` inside double quotes as a plain character, such an expansion is kept apart from an unquoted one.
    private const int QUOTED_WORD = 4;
    private const int QUOTED_SUBSCRIPT = 5;
    private const int SUBSTITUTION = 6;
    private const int SUBSHELL = 7;
    // Because a `)` that ends a case pattern closes nothing, a `case` stays open until its `esac`.
    private const int CASE = 8;
    private const int ARITHMETIC = 9;
    private const int ARITHMETIC_GROUP = 10;
    // Because a `case` reads its word up to `in` before its patterns start, the head is kept apart from the body.
    private const int CASE_HEAD = 11;
    // Because an expansion nested in any of these still stands inside the double quotes, it reads a `'` as a quoted expansion does.
    private const array QUOTED_FRAMES = [self::DOUBLE_QUOTES, self::QUOTED_PATTERN, self::QUOTED_WORD, self::QUOTED_SUBSCRIPT];
    // Because `<<` shifts inside arithmetic, it opens no here-document there.
    private const array ARITHMETIC_FRAMES = [self::ARITHMETIC, self::ARITHMETIC_GROUP];
    private const string NAME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
    private const string SPECIAL_PARAMETERS = '@*#?-$!';
    private const string PATTERN_OPERATORS = '#%/';
    // Because a `#` starts a comment only at the start of a word, these are the characters a word starts after.
    private const string WORD_BREAKS = " \t\n;&|()<>";
    private const string DOUBLE_QUOTED_SPECIALS = '\\"$';
    // Because `case` is a keyword only where a command starts, these are the characters a command starts after, and the ones that keep it starting.
    private const string COMMAND_BREAKS = "\n;&|(";
    private const string COMMAND_PREFIX_CHARACTERS = " \t{!";
    private const array COMMAND_PREFIX_WORDS = ['then', 'do', 'else', 'elif', 'if', 'while', 'until', 'time'];
    // Because a backslash in `$' '` escapes the next character, the quote ends at the first `'` no backslash escapes, or at the end of the source.
    private const string ANSI_C_QUOTED = '/(?:[^\'\\\\]++|\\\\.?)*+\'?/As';

    private readonly int $length;
    private readonly ShellHereDocuments $hereDocuments;
    private bool $atWordStart = true;
    private bool $atCommandStart = true;
    // Because a `(` before a case pattern only opens the pattern, the lexer knows where a pattern may start.
    private bool $atPatternStart = false;

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
        $this->hereDocuments = new ShellHereDocuments($source);
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
        $frame = array_last($this->frames);

        return match ($frame) {
            self::DOUBLE_QUOTES => $this->afterDoubleQuoted($offset),
            self::QUOTED_HEAD => $this->afterOperatorDecided($offset),
            self::QUOTED_SUBSCRIPT => $this->afterInSubscript($offset),
            self::EXPANSION, self::QUOTED_PATTERN, self::QUOTED_WORD => $this->afterInExpansion($offset, $frame),
            default => $this->afterUnquoted($offset, $frame),
        };
    }

    private function afterDoubleQuoted(int $offset): int
    {
        $char = $this->source[$offset];

        return match (true) {
            '\\' === $char => $offset + 2,
            '"' === $char => $this->afterClosing($offset),
            $this->expansionOpensAt($offset) => $this->afterExpansionOpening($offset + 2),
            $this->substitutionOpensAt($offset) => $this->afterSubstitutionOpening($offset + 2),
            default => $offset + 1 + strcspn($this->source, self::DOUBLE_QUOTED_SPECIALS, $offset + 1),
        };
    }

    private function afterOperatorDecided(int $offset): int
    {
        array_pop($this->frames);

        return $this->afterOpening($this->quotedKindAt($offset), $offset);
    }

    private function afterInSubscript(int $offset): int
    {
        $char = $this->source[$offset];

        return match ($char) {
            ']' => $this->afterClosing($offset),
            '\\' => $offset + 2,
            "'" => $offset + 1,
            default => $this->afterQuoteOrDollar($offset, $char),
        };
    }

    private function afterInExpansion(int $offset, int $frame): int
    {
        $char = $this->source[$offset];

        return match (true) {
            '}' === $char => $this->afterClosing($offset),
            '\\' === $char => $offset + 2,
            "'" === $char && self::QUOTED_WORD === $frame => $offset + 1,
            default => $this->afterQuoteOrDollar($offset, $char),
        };
    }

    private function afterUnquoted(int $offset, ?int $frame): int
    {
        $char = $this->source[$offset];

        if ('#' === $char && $this->atWordStart) {
            return $this->afterComment($offset);
        }

        if ($this->notePatternStart($offset)) {
            return $this->afterPatternOpening($offset);
        }

        if ('\\' === $char) {
            return $this->afterEscape($offset);
        }

        $word = $this->atWordStart ? strspn($this->source, self::NAME_CHARACTERS, $offset) : 0;

        if (0 < $word) {
            return $this->afterWord($offset, $word, $frame);
        }

        $this->noteBreakOrCharacter($char);

        return match ($char) {
            "\n" => $this->hereDocuments->afterBodies($offset + 1),
            '(' => $this->afterParenthesisOpening($offset, $frame),
            ')' => $this->afterParenthesisClosing($offset, $frame),
            '<' => $this->afterLess($offset, $frame),
            ';' => $this->afterSemicolon($offset, $frame),
            default => $this->afterQuoteOrDollar($offset, $char),
        };
    }

    private function afterClosing(int $offset): int
    {
        array_pop($this->frames);

        return $offset + 1;
    }

    private function expansionOpensAt(int $offset): bool
    {
        return '$' === $this->source[$offset] && '{' === ($this->source[$offset + 1] ?? '');
    }

    private function afterExpansionOpening(int $offset): int
    {
        if (!in_array(array_last($this->frames), self::QUOTED_FRAMES, true)) {
            return $this->afterOpening(self::EXPANSION, $offset);
        }

        $offset += strspn($this->source, '!', $offset, 1);
        $name = strspn($this->source, self::NAME_CHARACTERS, $offset);
        $offset += 0 === $name ? strspn($this->source, self::SPECIAL_PARAMETERS, $offset, 1) : $name;

        if ('[' === ($this->source[$offset] ?? '')) {
            $this->frames[] = self::QUOTED_HEAD;

            return $this->afterOpening(self::QUOTED_SUBSCRIPT, $offset + 1);
        }

        return $this->afterOpening($this->quotedKindAt($offset), $offset);
    }

    // Because `$((` opens arithmetic, only a `$(` followed by something else opens a command substitution.
    private function substitutionOpensAt(int $offset): bool
    {
        return '$' === $this->source[$offset] && '(' === ($this->source[$offset + 1] ?? '') && '(' !== ($this->source[$offset + 2] ?? '');
    }

    // Because the text of a `$( )` is read as a script of its own, a word and a command start right after its opening.
    private function afterSubstitutionOpening(int $offset): int
    {
        $this->hereDocuments->openSubstitution();
        $this->atWordStart = true;
        $this->atCommandStart = true;

        return $this->afterOpening(self::SUBSTITUTION, $offset);
    }

    private function quotedKindAt(int $offset): int
    {
        return str_contains(self::PATTERN_OPERATORS, $this->source[$offset] ?? '}') ? self::QUOTED_PATTERN : self::QUOTED_WORD;
    }

    private function afterOpening(int $frame, int $offset): int
    {
        $this->frames[] = $frame;

        return $offset;
    }

    private function afterQuoteOrDollar(int $offset, string $char): int
    {
        return match (true) {
            "'" === $char => $this->afterSingleQuoted($offset + 1),
            '"' === $char => $this->afterOpening(self::DOUBLE_QUOTES, $offset + 1),
            $this->expansionOpensAt($offset) => $this->afterExpansionOpening($offset + 2),
            $this->substitutionOpensAt($offset) => $this->afterSubstitutionOpening($offset + 2),
            '$' === $char && '((' === substr($this->source, $offset + 1, 2) => $this->afterOpening(self::ARITHMETIC, $offset + 3),
            '$' === $char && "'" === ($this->source[$offset + 1] ?? '') => $this->afterAnsiCQuoted($offset + 2),
            default => $offset + 1,
        };
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->source, "\n", $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];

        return $end;
    }

    // Because blanks, line feeds and a backslash that joins two lines may stand between a branch terminator and the next pattern, only another character ends the place where a pattern may start.
    private function notePatternStart(int $offset): bool
    {
        $char = $this->source[$offset];
        $wasPatternStart = $this->atPatternStart;
        $this->atPatternStart = $wasPatternStart && (str_contains(" \t\n", $char) || "\\\n" === substr($this->source, $offset, 2));

        return $wasPatternStart && '(' === $char;
    }

    private function afterPatternOpening(int $offset): int
    {
        $this->atWordStart = true;
        $this->atCommandStart = false;

        return $offset + 1;
    }

    // Because a backslash before a line feed joins two lines, it leaves the word and the command as they were.
    private function afterEscape(int $offset): int
    {
        if ("\n" !== ($this->source[$offset + 1] ?? '')) {
            $this->atWordStart = false;
            $this->atCommandStart = false;
        }

        return $offset + 2;
    }

    private function afterWord(int $offset, int $length, ?int $frame): int
    {
        $word = substr($this->source, $offset, $length);
        $end = $offset + $length;
        $isWhole = str_contains(self::WORD_BREAKS, $this->source[$this->afterLineJoins($end)] ?? "\n");
        $isCommand = $this->atCommandStart && $isWhole;
        $this->atWordStart = false;
        $this->atCommandStart = $isCommand && in_array($word, self::COMMAND_PREFIX_WORDS, true);

        return match (true) {
            $isWhole && 'in' === $word && self::CASE_HEAD === $frame => $this->afterCaseIn($end),
            !$isCommand => $end,
            'case' === $word => $this->afterOpening(self::CASE_HEAD, $end),
            'esac' === $word && self::CASE === $frame => $this->afterClosing($end - 1),
            default => $end,
        };
    }

    // Because bash removes a backslash before a line feed before it splits words, a word ends only at the first other character after such pairs.
    private function afterLineJoins(int $offset): int
    {
        while ("\\\n" === substr($this->source, $offset, 2)) {
            $offset += 2;
        }

        return $offset;
    }

    private function noteBreakOrCharacter(string $char): void
    {
        $this->atCommandStart = str_contains($this->atCommandStart ? self::COMMAND_BREAKS.self::COMMAND_PREFIX_CHARACTERS : self::COMMAND_BREAKS, $char);
        $this->atWordStart = str_contains(self::WORD_BREAKS, $char);
    }

    // Because `((` opens arithmetic and a parenthesis inside arithmetic only groups, neither is a subshell.
    private function afterParenthesisOpening(int $offset, ?int $frame): int
    {
        return match (true) {
            in_array($frame, self::ARITHMETIC_FRAMES, true) => $this->afterOpening(self::ARITHMETIC_GROUP, $offset + 1),
            '(' === ($this->source[$offset + 1] ?? '') => $this->afterOpening(self::ARITHMETIC, $offset + 2),
            default => $this->afterOpening(self::SUBSHELL, $offset + 1),
        };
    }

    private function afterParenthesisClosing(int $offset, ?int $frame): int
    {
        return match ($frame) {
            null => $offset + 1,
            self::CASE => $this->afterCasePattern($offset),
            self::SUBSTITUTION => $this->afterSubstitutionClosing($offset),
            self::ARITHMETIC => $this->afterClosing($offset) + strspn($this->source, ')', $offset + 1, 1),
            default => $this->afterClosing($offset),
        };
    }

    // Because `<<<` is a here-string and `<<` shifts in arithmetic, only another `<<` opens a here-document.
    private function afterLess(int $offset, ?int $frame): int
    {
        return match (true) {
            '<<<' === substr($this->source, $offset, 3) => $offset + 3,
            '<' === ($this->source[$offset + 1] ?? '') && !in_array($frame, self::ARITHMETIC_FRAMES, true) => $this->hereDocuments->afterOperator($offset),
            default => $offset + 1,
        };
    }

    // Because `;;`, `;&` and `;;&` end a case branch, a pattern may start after them.
    private function afterSemicolon(int $offset, ?int $frame): int
    {
        $terminator = self::CASE === $frame ? strspn($this->source, ';&', $offset + 1, 2) : 0;
        $this->atPatternStart = 0 < $terminator;

        return $offset + 1 + $terminator;
    }

    private function afterSingleQuoted(int $offset): int
    {
        $end = strpos($this->source, "'", $offset);

        return false === $end ? $this->length : $end + 1;
    }

    private function afterAnsiCQuoted(int $offset): int
    {
        preg_match(self::ANSI_C_QUOTED, $this->source, $quoted, 0, $offset);

        return $offset + strlen($quoted[0] ?? '');
    }

    private function afterCaseIn(int $offset): int
    {
        array_pop($this->frames);
        $this->atPatternStart = true;

        return $this->afterOpening(self::CASE, $offset);
    }

    private function afterCasePattern(int $offset): int
    {
        $this->atCommandStart = true;

        return $offset + 1;
    }

    // Because the word around a `$( )` goes on after it, `$(date)#x` holds no comment.
    private function afterSubstitutionClosing(int $offset): int
    {
        $this->hereDocuments->closeSubstitution();
        $this->atWordStart = false;
        $this->atCommandStart = false;

        return $this->afterClosing($offset);
    }
}
