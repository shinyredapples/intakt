<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a `#` starts a comment and whether `((` opens arithmetic depend on what came before, the lexer keeps its place in the command and the open substitutions of one source.
final class ShellLexer
{
    private const string BLANKS = " \t";
    private const string CONTROL_OPERATORS = ';|&';
    // Because bash ends a word only at a metacharacter, a here-document delimiter runs up to one.
    private const string METACHARACTERS = " \t\n;|&()<>";
    // Because a quote, an escape or an expansion inside a word is read on its own, a plain run of a word stops before one too.
    private const string WORD_STOPS = self::METACHARACTERS."'\"\\$`";
    private const array OPENS_A_COMMAND = ['{', '!', 'if', 'then', 'elif', 'else', 'while', 'until', 'do', 'for', 'time'];
    private const string FUNCTION_KEYWORD = 'function';
    private const string STRIPPING_DOCUMENT = '<<-';
    private const string DOCUMENT = '<<';
    private const string HERE_STRING = '<<<';
    private const int SUBSHELL = 0;
    private const int SUBSTITUTION = 1;
    private const int SUBSTITUTION_IN_STRING = 2;

    private readonly int $length;
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
        $char = $this->source[$offset];

        return match (true) {
            '#' === $char && $this->wordStart => $this->afterComment($offset),
            str_contains(self::BLANKS, $char) => $this->afterBlanks($offset),
            "\n" === $char => $this->afterLineEnd($offset),
            str_contains(self::CONTROL_OPERATORS, $char) => $this->atCommandStart($offset + 1),
            '(' === $char => $this->afterOpeningParenthesis($offset),
            ')' === $char => $this->afterClosingParenthesis($offset),
            '<' === $char => $this->afterLessThan($offset),
            '>' === $char => $this->atWordStart($offset + 1),
            "'" === $char => $this->inWord($this->afterSingleQuoted($offset + 1)),
            '"' === $char => $this->afterDoubleQuoted($offset + 1),
            '\\' === $char => $this->afterEscape($offset),
            '$' === $char => $this->afterDollar($offset),
            '`' === $char => $this->inWord($this->afterBackticks($offset + 1)),
            default => $this->afterWord($offset),
        };
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->source, "\n", $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];

        return $end;
    }

    private function afterBlanks(int $offset): int
    {
        $this->wordStart = true;

        return $offset + strspn($this->source, self::BLANKS, $offset);
    }

    // Because bash reads the bodies of the here-documents of a line right after that line ends, they are skipped there in their order.
    private function afterLineEnd(int $offset): int
    {
        $offset = $offset + 1;

        foreach ($this->pendingDocuments as $document) {
            $offset = $this->afterDocumentBody($offset, $document);
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
        if ($this->commandStart && '(' === ($this->source[$offset + 1] ?? '')) {
            return $this->inWord($this->afterArithmetic($offset + 2, 2));
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
        if (self::HERE_STRING === substr($this->source, $offset, 3)) {
            return $this->atWordStart($offset + 3);
        }

        if (self::DOCUMENT !== substr($this->source, $offset, 2)) {
            return $this->atWordStart($offset + 1);
        }

        $operator = self::STRIPPING_DOCUMENT === substr($this->source, $offset, 3) ? self::STRIPPING_DOCUMENT : self::DOCUMENT;

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

    private function afterSingleQuoted(int $offset): int
    {
        $end = strpos($this->source, "'", $offset);

        return false === $end ? $this->length : $end + 1;
    }

    private function afterDoubleQuoted(int $offset): int
    {
        while (($offset += strcspn($this->source, '"\\$`', $offset)) < $this->length) {
            $char = $this->source[$offset];

            if ('"' === $char) {
                return $this->inWord($offset + 1);
            }

            if ($this->substitutionOpensAt($offset)) {
                // Because a command substitution is code until its `)`, the string resumes only when that parenthesis closes this frame.
                $this->frames[] = self::SUBSTITUTION_IN_STRING;

                return $this->atCommandStart($offset + 2);
            }

            $offset = match ($char) {
                '\\' => min($offset + 2, $this->length),
                '`' => $this->afterBackticks($offset + 1),
                default => $this->afterExpansion($offset),
            };
        }

        return $this->length;
    }

    // Because a backslash before a line end joins two lines, the state of the command goes on across it.
    private function afterEscape(int $offset): int
    {
        if ("\n" === ($this->source[$offset + 1] ?? '')) {
            return $offset + 2;
        }

        return $this->inWord(min($offset + 2, $this->length));
    }

    private function afterDollar(int $offset): int
    {
        $next = $this->source[$offset + 1] ?? '';

        return match (true) {
            "'" === $next => $this->inWord($this->afterAnsiCQuoted($offset + 2)),
            '"' === $next => $this->afterDoubleQuoted($offset + 2),
            $this->substitutionOpensAt($offset) => $this->openSubstitution($offset),
            default => $this->inWord($this->afterExpansion($offset)),
        };
    }

    private function afterBackticks(int $offset): int
    {
        while (($offset += strcspn($this->source, '`\\', $offset)) < $this->length) {
            if ('`' === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset = min($offset + 2, $this->length);
        }

        return $this->length;
    }

    private function afterWord(int $offset): int
    {
        $end = max($offset + 1, $offset + strcspn($this->source, self::WORD_STOPS, $offset));
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

    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     */
    private function afterDocumentBody(int $offset, array $document): int
    {
        while ($offset < $this->length) {
            [$line, $offset] = $this->documentLineAt($offset, $document);

            if ($line === $document['delimiter']) {
                return $offset;
            }
        }

        return $this->length;
    }

    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     *
     * @return array{string, int}
     */
    private function documentLineAt(int $offset, array $document): array
    {
        // Because only whether the line equals the delimiter matters, a line is kept no longer than one byte past it, so a long chain of joined lines is read in linear time.
        $kept = strlen($document['delimiter']) + 1;
        $line = '';

        do {
            $end = $offset + strcspn($this->source, "\n", $offset);
            $start = '' === $line && $document['stripsTabs'] ? $offset + strspn($this->source, "\t", $offset, $end - $offset) : $offset;
            $continues = $document['joinsLines'] && $end < $this->length && $this->endsInAnEscape($start, $end);
            $line = substr($line.substr($this->source, $start, min($end - $start - ($continues ? 1 : 0), $kept)), 0, $kept);
            $offset = $end + 1;
        } while ($continues);

        return [$line, min($offset, $this->length)];
    }

    // Because a backslash escapes the next one, only an odd run of backslashes before the line end joins the next line.
    private function endsInAnEscape(int $start, int $end): bool
    {
        $line = substr($this->source, $start, $end - $start);

        return 1 === (strlen($line) - strlen(rtrim($line, '\\'))) % 2;
    }

    private function afterArithmetic(int $offset, int $depth): int
    {
        while (($offset += strcspn($this->source, '()', $offset)) < $this->length) {
            $depth += '(' === $this->source[$offset] ? 1 : -1;
            ++$offset;

            if (0 === $depth) {
                return $offset;
            }
        }

        return $this->length;
    }

    // Because bash takes the delimiter with its quotes removed and reads the body as it stands once any part of the delimiter was quoted.
    private function afterDocumentDelimiter(int $offset, string $operator): int
    {
        $offset += strspn($this->source, self::BLANKS, $offset);
        $delimiter = '';
        $quoted = false;

        while ($offset < $this->length && !str_contains(self::METACHARACTERS, $this->source[$offset])) {
            [$part, $offset, $partQuoted] = $this->delimiterPartAt($offset);
            $delimiter .= $part;
            $quoted = $quoted || $partQuoted;
        }

        if ('' !== $delimiter || $quoted) {
            $this->pendingDocuments[] = ['delimiter' => $delimiter, 'joinsLines' => !$quoted, 'stripsTabs' => self::STRIPPING_DOCUMENT === $operator];
        }

        return $this->inWord($offset);
    }

    /**
     * @return array{string, int, bool}
     */
    private function delimiterPartAt(int $offset): array
    {
        // Because the `$` of ANSI-C or locale quoting is removed with the quotes, `$'EOF'` is the delimiter `EOF`.
        if ('$' === $this->source[$offset] && in_array($this->source[$offset + 1] ?? '', ["'", '"'], true)) {
            ++$offset;
        }

        $char = $this->source[$offset];

        return match ($char) {
            "'", '"' => $this->quotedDelimiterPart($offset, $char),
            '\\' => [substr($this->source, $offset + 1, 1), min($offset + 2, $this->length), true],
            default => [$char, $offset + 1, false],
        };
    }

    /**
     * @return array{string, int, bool}
     */
    private function quotedDelimiterPart(int $offset, string $quote): array
    {
        $end = strpos($this->source, $quote, $offset + 1);
        $end = false === $end ? $this->length : $end;

        return [substr($this->source, $offset + 1, $end - $offset - 1), min($end + 1, $this->length), true];
    }

    private function substitutionOpensAt(int $offset): bool
    {
        return '$(' === substr($this->source, $offset, 2) && '(' !== ($this->source[$offset + 2] ?? '');
    }

    private function afterExpansion(int $offset): int
    {
        return match (true) {
            '$((' === substr($this->source, $offset, 3) => $this->afterArithmetic($offset + 3, 2),
            '${' === substr($this->source, $offset, 2) => $this->afterParameter($offset + 2),
            default => $offset + 1,
        };
    }

    // Because a `#` in `${#name}` or `${name#prefix}` is an operator, a parameter expansion is skipped to its own closing brace.
    private function afterParameter(int $offset): int
    {
        $depth = 1;

        while (($offset += strcspn($this->source, '{}\\', $offset)) < $this->length) {
            $char = $this->source[$offset];
            $offset = '\\' === $char ? min($offset + 2, $this->length) : $offset + 1;
            $depth += match ($char) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if (0 === $depth) {
                return $offset;
            }
        }

        return $this->length;
    }

    private function afterAnsiCQuoted(int $offset): int
    {
        while (($offset += strcspn($this->source, "'\\", $offset)) < $this->length) {
            if ("'" === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset = min($offset + 2, $this->length);
        }

        return $this->length;
    }

    private function openSubstitution(int $offset): int
    {
        $this->frames[] = self::SUBSTITUTION;

        return $this->atCommandStart($offset + 2);
    }

    // Because a reserved word counts only as a whole word at the start of a command, any other run of characters is returned as no word.
    private function wholeWordAt(int $offset, int $end): ?string
    {
        if (!$this->commandStart || !$this->wordStart || ($end < $this->length && !str_contains(self::METACHARACTERS, $this->source[$end]))) {
            return null;
        }

        return substr($this->source, $offset, $end - $offset);
    }
}
