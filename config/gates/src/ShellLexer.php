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
    private const int CASE_ARMS = 2;
    private const int STRING = 3;
    private const int PARAMETER = 4;
    // Because a string and a `${ }` expansion are text up to their closer, each is searched only for the characters that close it, escape in it or open something inside it.
    private const array TEXT_STOPS = [self::STRING => '"\\$`', self::PARAMETER => "}\\'\"`\$"];
    private const array TEXT_CLOSERS = [self::STRING => '"', self::PARAMETER => '}'];
    private const string CASE_PATTERN_ENDS = ')|';
    private const string CASE_KEYWORD = 'case';
    private const string CASE_END = 'esac';

    private readonly ShellLiterals $literals;
    private readonly ShellHereDocuments $documents;
    private bool $wordStart = true;
    private bool $commandStart = true;
    private bool $functionName = false;

    /**
     * @var list<int>
     */
    private array $frames = [];

    private int $substitutions = 0;

    // Because only a `$( )` is read as a source of its own, here-documents wait by the number of open substitutions: a line end in a subshell or a `case` reads every body queued on that line.
    /**
     * @var array<int, list<array{delimiter: string, joinsLines: bool, stripsTabs: bool}>>
     */
    private array $pendingDocuments = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(string $source)
    {
        $this->literals = new ShellLiterals($source);
        $this->documents = new ShellHereDocuments($source);
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
            '"' === $char => $this->afterOpenedText($offset + 1, self::STRING),
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

        foreach ($this->pendingDocuments[$this->substitutions] ?? [] as $document) {
            $offset = $this->documents->afterBody($offset, $document);
        }

        unset($this->pendingDocuments[$this->substitutions]);

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
        if (self::CASE_ARMS === array_last($this->frames)) {
            return $this->atCommandStart($offset + 1);
        }

        return self::SUBSTITUTION === $this->closedFrame() ? $this->afterText($offset + 1) : $this->atCommandStart($offset + 1);
    }

    // Because a here-document opened in a substitution that closes on its own line, as in `$(cat <<EOF)`, is read at the enclosing line end.
    private function closedFrame(): ?int
    {
        $frame = array_pop($this->frames);

        if (self::SUBSTITUTION !== $frame) {
            return $frame;
        }

        $scope = $this->substitutions--;

        // Because copying the outer queue on every close would make many substitutions on one line quadratic, the inner documents are appended to it in place.
        foreach ($this->pendingDocuments[$scope] ?? [] as $document) {
            $this->pendingDocuments[$scope - 1][] = $document;
        }

        unset($this->pendingDocuments[$scope]);

        return $frame;
    }

    // Because a string and a `${ }` expansion nest in each other and both can hold a `$( )` of code, the text goes on in the innermost open one until code starts or the word goes on.
    private function afterText(int $offset): int
    {
        while (null !== ($frame = array_last($this->frames)) && null !== $stops = self::TEXT_STOPS[$frame] ?? null) {
            $offset += strcspn($this->literals->source, $stops, $offset);

            if ($offset >= $this->literals->length) {
                return $this->literals->length;
            }

            if ($this->substitutionOpensAt($offset)) {
                return $this->openSubstitution($offset);
            }

            $offset = $this->afterTextCharacter($offset, $frame);
        }

        return $this->inWord($offset);
    }

    private function substitutionOpensAt(int $offset): bool
    {
        return '$(' === substr($this->literals->source, $offset, 2) && '(' !== ($this->literals->source[$offset + 2] ?? '');
    }

    private function openSubstitution(int $offset): int
    {
        $this->frames[] = self::SUBSTITUTION;
        ++$this->substitutions;

        return $this->atCommandStart($offset + 2);
    }

    // Because a `#` in `${#name}` or `${name#prefix}` is an operator and a brace in a quoted default such as `${name:-'}'}` closes nothing, an expansion ends only at a `}` outside the quotes and expansions inside it, while a plain `{` opens nothing, as in `${name:-{}`.
    private function afterTextCharacter(int $offset, int $frame): int
    {
        $char = $this->literals->source[$offset];

        if (self::TEXT_CLOSERS[$frame] === $char) {
            array_pop($this->frames);

            return $offset + 1;
        }

        return match ($char) {
            '\\' => min($offset + 2, $this->literals->length),
            "'" => $this->insideDoubleQuotes() ? $offset + 1 : $this->literals->afterSingleQuoted($offset + 1),
            '`' => $this->literals->afterEscapedText($offset + 1, '`'),
            '"' => $this->afterOpenedFrame($offset + 1, self::STRING),
            default => '{' === ($this->literals->source[$offset + 1] ?? '') ? $this->afterOpenedFrame($offset + 2, self::PARAMETER) : $this->literals->afterExpansion($offset),
        };
    }

    // Because bash reads a `'` in a `${ }` inside a `"..."` string as a plain character, as in `"${message:-don't stop}"`, while a `$( )` starts its quoting afresh.
    private function insideDoubleQuotes(): bool
    {
        $index = count($this->frames) - 1;

        while ($index >= 0 && self::PARAMETER === $this->frames[$index]) {
            --$index;
        }

        return self::STRING === ($this->frames[$index] ?? null);
    }

    private function afterOpenedFrame(int $offset, int $frame): int
    {
        $this->frames[] = $frame;

        return $offset;
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

    private function afterOpenedText(int $offset, int $frame): int
    {
        $this->frames[] = $frame;

        return $this->afterText($offset);
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
            '"' => $this->afterOpenedText($offset + 2, self::STRING),
            '{' => $this->afterOpenedText($offset + 2, self::PARAMETER),
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

        // Because the `)` that ends a `case` pattern closes no substitution, the arms are a frame of their own until `esac`.
        if (self::CASE_KEYWORD === $word && !$this->aCasePatternEndsAt($end)) {
            $this->frames[] = self::CASE_ARMS;
        }

        if (self::CASE_END === $word && self::CASE_ARMS === array_last($this->frames)) {
            $this->closedFrame();
        }

        $this->functionName = self::FUNCTION_KEYWORD === $word;

        return $this->inWord($end);
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

    // Because a `case` right before `)` or `|` is a pattern of the enclosing `case`, as in `case) :;;`, and opens no arms of its own.
    private function aCasePatternEndsAt(int $end): bool
    {
        $next = $this->literals->source[$end + strspn($this->literals->source, ShellLiterals::BLANKS, $end)] ?? "\n";

        return str_contains(self::CASE_PATTERN_ENDS, $next);
    }

    private function afterDocumentDelimiter(int $offset, string $operator): int
    {
        [$delimiter, $end, $quoted] = $this->literals->documentDelimiterAt($offset);

        if ('' !== $delimiter || $quoted) {
            $this->pendingDocuments[$this->substitutions][] = ['delimiter' => $delimiter, 'joinsLines' => !$quoted, 'stripsTabs' => self::STRIPPING_DOCUMENT === $operator];
        }

        return $this->inWord($end);
    }
}
