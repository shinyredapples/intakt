<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a `#` starts a comment and whether `((` opens arithmetic depend on what came before, the lexer keeps its place in the command and the open substitutions of one source.
final class ShellLexer
{
    private const string CONTROL_OPERATORS = ';|&';
    // Because a quote, an escape or an expansion inside a word is read on its own, a plain run of a word stops before one too.
    private const string WORD_STOPS = ShellLiterals::METACHARACTERS."'\"\\$`";
    private const array OPENS_A_COMMAND = ['{', '!', 'if', 'then', 'elif', 'else', 'while', 'until', 'do', 'for', 'time'];
    private const string FUNCTION_KEYWORD = 'function';
    private const string STRIPPING_DOCUMENT = '<<-';
    private const string DOCUMENT = '<<';
    private const string HERE_STRING = '<<<';
    private const int SUBSHELL = 0;
    private const int SUBSTITUTION = 1;
    private const int SUBSTITUTION_IN_STRING = 2;

    private readonly ShellLiterals $literals;
    private bool $wordStart = true;
    private bool $commandStart = true;
    private bool $functionName = false;

    /**
     * @var list<int>
     */
    private array $frames = [];

    /**
     * @var list<array{delimiter: string, joinsLines: bool, stripsTabs: bool}>
     */
    private array $pendingDocuments = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(string $source)
    {
        $this->literals = new ShellLiterals($source);
    }

    /**
     * @return list<array{int, string}>
     */
    public function comments(): array
    {
        $offset = 0;

        while ($offset < $this->literals->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    private function afterToken(int $offset): int
    {
        $char = $this->literals->source[$offset];

        return match (true) {
            '#' === $char && $this->wordStart => $this->afterComment($offset),
            str_contains(ShellLiterals::BLANKS, $char) => $this->afterBlanks($offset),
            "\n" === $char => $this->afterLineEnd($offset),
            str_contains(self::CONTROL_OPERATORS, $char) => $this->atCommandStart($offset + 1),
            '(' === $char => $this->afterOpeningParenthesis($offset),
            ')' === $char => $this->afterClosingParenthesis($offset),
            '<' === $char => $this->afterLessThan($offset),
            '>' === $char => $this->atWordStart($offset + 1),
            "'" === $char => $this->inWord($this->literals->afterSingleQuoted($offset + 1)),
            '"' === $char => $this->afterDoubleQuoted($offset + 1),
            '\\' === $char => $this->afterEscape($offset),
            '$' === $char => $this->afterDollar($offset),
            '`' === $char => $this->inWord($this->literals->afterEscapedText($offset + 1, '`')),
            default => $this->afterWord($offset),
        };
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->literals->source, "\n", $offset);
        $this->comments[] = [$offset, substr($this->literals->source, $offset, $end - $offset)];

        return $end;
    }

    private function afterBlanks(int $offset): int
    {
        $this->wordStart = true;

        return $offset + strspn($this->literals->source, ShellLiterals::BLANKS, $offset);
    }

    // Because bash reads the bodies of the here-documents of a line right after that line ends, they are skipped there in their order.
    private function afterLineEnd(int $offset): int
    {
        ++$offset;

        foreach ($this->pendingDocuments as $document) {
            $offset = $this->literals->afterDocumentBody($offset, $document);
        }

        $this->pendingDocuments = [];

        return $this->atCommandStart($offset);
    }

    private function atCommandStart(int $end): int
    {
        $this->wordStart = true;
        $this->commandStart = true;

        return $end;
    }

    // Because only at the start of a command does `((` open arithmetic, whose `<<` shifts and starts no here-document.
    private function afterOpeningParenthesis(int $offset): int
    {
        if ($this->commandStart && '(' === ($this->literals->source[$offset + 1] ?? '')) {
            return $this->inWord($this->literals->afterArithmetic($offset + 2, 2));
        }

        $this->frames[] = self::SUBSHELL;

        return $this->atCommandStart($offset + 1);
    }

    // Because the `)` after a function's name, a subshell or a `case` pattern is followed by a command, while one that closes a substitution ends part of a word.
    private function afterClosingParenthesis(int $offset): int
    {
        return match (array_pop($this->frames)) {
            self::SUBSTITUTION_IN_STRING => $this->afterDoubleQuoted($offset + 1),
            self::SUBSTITUTION => $this->inWord($offset + 1),
            default => $this->atCommandStart($offset + 1),
        };
    }

    private function afterLessThan(int $offset): int
    {
        $source = $this->literals->source;

        if (self::HERE_STRING === substr($source, $offset, 3)) {
            return $this->atWordStart($offset + 3);
        }

        if (self::DOCUMENT !== substr($source, $offset, 2)) {
            return $this->atWordStart($offset + 1);
        }

        $operator = self::STRIPPING_DOCUMENT === substr($source, $offset, 3) ? self::STRIPPING_DOCUMENT : self::DOCUMENT;

        return $this->afterDocumentDelimiter($offset + strlen($operator), $operator);
    }

    private function atWordStart(int $end): int
    {
        $this->wordStart = true;
        $this->commandStart = false;

        return $end;
    }

    private function inWord(int $end): int
    {
        $this->wordStart = false;
        $this->commandStart = false;

        return $end;
    }

    private function afterDoubleQuoted(int $offset): int
    {
        $source = $this->literals->source;

        while (($offset += strcspn($source, '"\\$`', $offset)) < $this->literals->length) {
            if ('"' === $source[$offset]) {
                return $this->inWord($offset + 1);
            }

            if ($this->substitutionOpensAt($offset)) {
                // Because a command substitution is code until its `)`, the string resumes only when that parenthesis closes this frame.
                $this->frames[] = self::SUBSTITUTION_IN_STRING;

                return $this->atCommandStart($offset + 2);
            }

            $offset = match ($source[$offset]) {
                '\\' => min($offset + 2, $this->literals->length),
                '`' => $this->literals->afterEscapedText($offset + 1, '`'),
                default => $this->literals->afterExpansion($offset),
            };
        }

        return $this->literals->length;
    }

    // Because a backslash before a line end joins two lines, the state of the command goes on across it.
    private function afterEscape(int $offset): int
    {
        if ("\n" === ($this->literals->source[$offset + 1] ?? '')) {
            return $offset + 2;
        }

        return $this->inWord(min($offset + 2, $this->literals->length));
    }

    private function afterDollar(int $offset): int
    {
        return match ($this->literals->source[$offset + 1] ?? '') {
            "'" => $this->inWord($this->literals->afterEscapedText($offset + 2, "'")),
            '"' => $this->afterDoubleQuoted($offset + 2),
            default => $this->substitutionOpensAt($offset) ? $this->openSubstitution($offset) : $this->inWord($this->literals->afterExpansion($offset)),
        };
    }

    private function afterWord(int $offset): int
    {
        $end = max($offset + 1, $offset + strcspn($this->literals->source, self::WORD_STOPS, $offset));
        $word = $this->wholeWordAt($offset, $end);

        if ($this->functionName) {
            // Because after `function` and its name the `{` that opens the body starts a command, as after `()`.
            $this->functionName = false;

            return $this->atCommandStart($end);
        }

        if (in_array($word, self::OPENS_A_COMMAND, true)) {
            return $this->atCommandStart($end);
        }

        $this->functionName = self::FUNCTION_KEYWORD === $word;

        return $this->inWord($end);
    }

    private function afterDocumentDelimiter(int $offset, string $operator): int
    {
        [$delimiter, $end, $quoted] = $this->literals->documentDelimiterAt($offset);

        if ('' !== $delimiter || $quoted) {
            $this->pendingDocuments[] = ['delimiter' => $delimiter, 'joinsLines' => !$quoted, 'stripsTabs' => self::STRIPPING_DOCUMENT === $operator];
        }

        return $this->inWord($end);
    }

    private function substitutionOpensAt(int $offset): bool
    {
        return '$(' === substr($this->literals->source, $offset, 2) && '(' !== ($this->literals->source[$offset + 2] ?? '');
    }

    private function openSubstitution(int $offset): int
    {
        $this->frames[] = self::SUBSTITUTION;

        return $this->atCommandStart($offset + 2);
    }

    // Because a reserved word counts only as a whole word at the start of a command, any other run of characters is returned as no word.
    private function wholeWordAt(int $offset, int $end): ?string
    {
        $endsAWord = $end >= $this->literals->length || str_contains(ShellLiterals::METACHARACTERS, $this->literals->source[$end]);

        if (!$this->commandStart || !$this->wordStart || !$endsAWord) {
            return null;
        }

        return substr($this->literals->source, $offset, $end - $offset);
    }
}
