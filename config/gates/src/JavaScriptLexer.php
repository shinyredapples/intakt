<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a slash starts a pattern or divides depends on the token before it, the lexer keeps that token's kind and the open brackets for one source.
final class JavaScriptLexer
{
    private const int OPERAND = 0;
    private const int OPERATOR = 1;
    private const int DOT = 2;
    private const int HEAD_KEYWORD = 3;
    private const int CLOSING_PARENTHESIS = 4;
    private const int STATEMENT_END = 5;
    private const int FOR_KEYWORD = 6;
    private const int EXPORT = 7;
    private const int DEFAULT_EXPORT = 8;
    private const array ENDS_AN_OPERAND = [self::OPERAND, self::CLOSING_PARENTHESIS];
    private const array OPENS_A_BLOCK = [self::OPERAND, self::CLOSING_PARENTHESIS, self::STATEMENT_END, self::EXPORT];
    private const array PARENTHESES_AFTER = [self::FOR_KEYWORD => self::FOR_HEAD, self::HEAD_KEYWORD => self::HEAD_PARENTHESIS];
    private const array STARTS_A_DECLARATION = [self::STATEMENT_END, self::EXPORT, self::DEFAULT_EXPORT];
    private const int PARENTHESIS = 0;
    private const int HEAD_PARENTHESIS = 1;
    private const int BRACKET = 2;
    private const int BRACE = 3;
    private const int TEMPLATE_EXPRESSION = 4;
    private const int BLOCK = 5;
    private const int FOR_HEAD = 6;
    private const int EXPRESSION_BODY = 7;
    private const array HOLDS_STATEMENTS = [self::BLOCK, self::EXPRESSION_BODY, null];
    private const array BODY_KEYWORDS = ['function', 'class'];
    // Because a `default` after `export` is read on its own, the kind given here is that of a `default` in a `switch`.
    private const array KEYWORD_KINDS = [
        'for' => self::FOR_KEYWORD, 'export' => self::EXPORT,
        'if' => self::HEAD_KEYWORD, 'while' => self::HEAD_KEYWORD, 'with' => self::HEAD_KEYWORD,
        'do' => self::STATEMENT_END, 'else' => self::STATEMENT_END, 'try' => self::STATEMENT_END, 'finally' => self::STATEMENT_END,
        'return' => self::OPERATOR, 'typeof' => self::OPERATOR, 'case' => self::OPERATOR, 'default' => self::OPERATOR, 'in' => self::OPERATOR,
        'of' => self::OPERATOR, 'new' => self::OPERATOR, 'delete' => self::OPERATOR, 'void' => self::OPERATOR, 'throw' => self::OPERATOR,
        'instanceof' => self::OPERATOR, 'yield' => self::OPERATOR, 'await' => self::OPERATOR, 'const' => self::OPERATOR, 'let' => self::OPERATOR,
        'var' => self::OPERATOR,
    ];

    private readonly JavaScriptLiterals $literals;
    private int $previous = self::STATEMENT_END;

    /**
     * @var list<int>
     */
    private array $frames = [];

    // Because a function or class in an expression is an operand once its body closes, the depth at which that body's `{` will open is kept until it does.
    /**
     * @var array<int, true>
     */
    private array $expressionBodies = [];

    /**
     * @var array<int, int>
     */
    private array $openTernaries = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(string $source)
    {
        $this->literals = new JavaScriptLiterals($source);
    }

    /**
     * @return list<array{int, string}>
     */
    public function comments(): array
    {
        $offset = $this->literals->afterHashbang();

        while ($offset < $this->literals->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    private function afterToken(int $offset): int
    {
        $char = $this->literals->source[$offset];
        $space = $this->literals->multibyteSpaceLengthAt($offset);

        return match (true) {
            str_contains(JavaScriptLiterals::WHITESPACE, $char) => $offset + strspn($this->literals->source, JavaScriptLiterals::WHITESPACE, $offset),
            0 < $space => $offset + $space,
            '/' === $char => $this->afterSlash($offset),
            "'" === $char, '"' === $char => $this->after(self::OPERAND, $this->literals->afterString($offset + 1, $char)),
            '`' === $char => $this->afterTemplateText($offset + 1),
            $this->literals->numberStartsAt($offset) => $this->after(self::OPERAND, $this->literals->afterNumber($offset)),
            $this->literals->wordStartsAt($offset) => $this->afterWord($offset),
            default => $this->afterPunctuator($offset, $char),
        };
    }

    private function afterSlash(int $offset): int
    {
        return match (true) {
            '/' === ($this->literals->source[$offset + 1] ?? '') => $this->afterLineComment($offset),
            '*' === ($this->literals->source[$offset + 1] ?? '') => $this->afterBlockComment($offset),
            !in_array($this->previous, self::ENDS_AN_OPERAND, true) => $this->after(self::OPERAND, $this->literals->afterRegex($offset + 1)),
            default => $this->after(self::OPERATOR, $offset + 1),
        };
    }

    private function afterLineComment(int $offset): int
    {
        $end = $this->literals->lineCommentEnd($offset);
        $this->comments[] = [$offset, substr($this->literals->source, $offset, $end - $offset)];

        return $end;
    }

    // Because U+2028 and U+2029 end a line inside a block comment too, each part is reported from its own offset, so the comment is never read again from the start of its git line.
    private function afterBlockComment(int $offset): int
    {
        $end = $this->literals->blockCommentEnd($offset);
        $text = substr($this->literals->source, $offset, $end - $offset);
        $start = 0;

        foreach ($this->separatorsIn($text) as $separator) {
            $this->comments[] = [$offset + $start, substr($text, $start, $separator - $start)];
            $start = $separator + 3;
        }

        $this->comments[] = [$offset + $start, substr($text, $start)];

        return $end;
    }

    /**
     * @return list<int>
     */
    private function separatorsIn(string $text): array
    {
        $separators = [];
        $at = 0;

        while (false !== $at = strpos($text, "\xE2\x80", $at)) {
            if (in_array(substr($text, $at, 3), [JavaScriptLiterals::LINE_SEPARATOR, JavaScriptLiterals::PARAGRAPH_SEPARATOR], true)) {
                $separators[] = $at;
            }

            $at += 2;
        }

        return $separators;
    }

    private function after(int $previous, int $end): int
    {
        $this->previous = $previous;

        return $end;
    }

    private function afterTemplateText(int $offset): int
    {
        $source = $this->literals->source;

        while (($offset += strcspn($source, '`\\$', $offset)) < $this->literals->length) {
            if ('`' === $source[$offset]) {
                return $this->after(self::OPERAND, $offset + 1);
            }

            if ('$' === $source[$offset] && '{' === ($source[$offset + 1] ?? '')) {
                // Because the expression is code until its closing brace, the template text resumes only when that brace closes this frame.
                return $this->open(self::TEMPLATE_EXPRESSION, $offset + 1);
            }

            $offset = '\\' === $source[$offset] ? $this->literals->afterEscape($offset) : $offset + 1;
        }

        return $this->literals->length;
    }

    private function afterWord(int $offset): int
    {
        $end = $this->literals->afterWord($offset);
        $word = substr($this->literals->source, $offset, $end - $offset);

        return match (true) {
            self::DOT === $this->previous => $this->after(self::OPERAND, $end),
            // Because `for await (` heads a loop like `for (`, the keyword between them keeps the head.
            'await' === $word && self::FOR_KEYWORD === $this->previous => $end,
            // Because `async function` is read like `function`, the word before it keeps the position it stands in.
            'async' === $word && $this->literals->functionFollows($end) => $end,
            // Because `of` is a keyword only right after the binding of a `for` head that has no `;`, elsewhere it names a variable.
            'of' === $word && !$this->bindingOfAForOfHeadEnded() => $this->after(self::OPERAND, $end),
            // Because after `export default` a `function` or `class` is a declaration, while a `default` in a `switch` precedes an operand.
            'default' === $word && self::EXPORT === $this->previous => $this->after(self::DEFAULT_EXPORT, $end),
            in_array($word, self::BODY_KEYWORDS, true) => $this->afterBodyKeyword($end),
            default => $this->after(self::KEYWORD_KINDS[$word] ?? self::OPERAND, $end),
        };
    }

    private function afterPunctuator(int $offset, string $char): int
    {
        return match ($char) {
            '(' => $this->open(self::PARENTHESES_AFTER[$this->previous] ?? self::PARENTHESIS, $offset),
            '[' => $this->open(self::BRACKET, $offset),
            '{' => $this->afterOpeningBrace($offset),
            ')', ']' => $this->afterClosingBracket($offset),
            '}' => $this->afterClosingBrace($offset),
            '.' => $this->afterDot($offset),
            '+', '-' => $this->afterPlusOrMinus($offset, $char),
            '?' => $this->afterQuestionMark($offset),
            ':' => $this->afterColon($offset),
            // Because the name after `#` is a private property, it is never a keyword, as after a `.`.
            '#' => $this->after(self::DOT, $offset + 1),
            ';' => $this->afterSemicolon($offset),
            '=' => $this->afterEquals($offset),
            default => $this->after(self::OPERATOR, $offset + 1),
        };
    }

    private function bindingOfAForOfHeadEnded(): bool
    {
        return self::FOR_HEAD === array_last($this->frames) && in_array($this->previous, self::ENDS_AN_OPERAND, true);
    }

    // Because a function or class that stands where an operand is expected is an expression, while one at the start of a statement or after `export` is a declaration, and one before a `:` is a property name.
    private function afterBodyKeyword(int $end): int
    {
        if (!in_array($this->previous, self::STARTS_A_DECLARATION, true) && !$this->literals->colonFollows($end)) {
            $this->expressionBodies[count($this->frames)] = true;
        }

        return $this->after(self::OPERAND, $end);
    }

    // Because in ordinary code a brace after an operator opens an object and any other brace opens a block or a class or function body.
    private function afterOpeningBrace(int $offset): int
    {
        $depth = count($this->frames);

        if (isset($this->expressionBodies[$depth])) {
            unset($this->expressionBodies[$depth]);

            return $this->open(self::EXPRESSION_BODY, $offset);
        }

        return $this->open(in_array($this->previous, self::OPENS_A_BLOCK, true) ? self::BLOCK : self::BRACE, $offset);
    }

    private function open(int $frame, int $offset): int
    {
        $this->frames[] = $frame;

        return $this->after(in_array($frame, [self::BLOCK, self::EXPRESSION_BODY], true) ? self::STATEMENT_END : self::OPERATOR, $offset + 1);
    }

    // Because the parenthesis that closes an `if`, `while`, `for` or `with` head ends no operand, a slash after it starts a pattern.
    private function afterClosingBracket(int $offset): int
    {
        $frame = self::TEMPLATE_EXPRESSION === array_last($this->frames) ? null : $this->close();

        return $this->after(match ($frame) {
            self::HEAD_PARENTHESIS, self::FOR_HEAD => self::STATEMENT_END,
            self::PARENTHESIS => self::CLOSING_PARENTHESIS,
            default => self::OPERAND,
        }, $offset + 1);
    }

    private function close(): ?int
    {
        $depth = count($this->frames);
        unset($this->expressionBodies[$depth], $this->openTernaries[$depth]);

        return array_pop($this->frames);
    }

    // Because a statement may follow the brace that closes a block, a slash after it starts a pattern, while after an object or a function or class expression it divides.
    private function afterClosingBrace(int $offset): int
    {
        return match ($this->close()) {
            self::TEMPLATE_EXPRESSION => $this->afterTemplateText($offset + 1),
            self::BLOCK => $this->after(self::STATEMENT_END, $offset + 1),
            default => $this->after(self::OPERAND, $offset + 1),
        };
    }

    private function afterDot(int $offset): int
    {
        if ('..' === substr($this->literals->source, $offset + 1, 2)) {
            return $this->after(self::OPERATOR, $offset + 3);
        }

        return $this->after(self::DOT, $offset + 1);
    }

    // Because `x++ / 2` divides, an increment or decrement ends an operand.
    private function afterPlusOrMinus(int $offset, string $char): int
    {
        if ($char === ($this->literals->source[$offset + 1] ?? '')) {
            return $this->after(self::OPERAND, $offset + 2);
        }

        return $this->after(self::OPERATOR, $offset + 1);
    }

    // Because `?.` and `??` are no conditional, only a lone `?` waits for its `:`.
    private function afterQuestionMark(int $offset): int
    {
        $next = $this->literals->source[$offset + 1] ?? '';

        if ('?' === $next) {
            return $this->after(self::OPERATOR, $offset + 2);
        }

        if ($this->literals->optionalChainingAt($offset)) {
            return $this->after(self::DOT, $offset + 2);
        }

        $depth = count($this->frames);
        $this->openTernaries[$depth] = ($this->openTernaries[$depth] ?? 0) + 1;

        return $this->after(self::OPERATOR, $offset + 1);
    }

    // Because a `:` among statements that answers no `?` ends a `case`, a `default` or a label, a statement follows it.
    private function afterColon(int $offset): int
    {
        $depth = count($this->frames);

        if (0 < ($this->openTernaries[$depth] ?? 0)) {
            --$this->openTernaries[$depth];

            return $this->after(self::OPERATOR, $offset + 1);
        }

        return $this->after(in_array(array_last($this->frames), self::HOLDS_STATEMENTS, true) ? self::STATEMENT_END : self::OPERATOR, $offset + 1);
    }

    // Because a `;` in a `for` head makes it a classic loop, `of` in it names a variable.
    private function afterSemicolon(int $offset): int
    {
        if (self::FOR_HEAD === array_last($this->frames)) {
            array_pop($this->frames);
            $this->frames[] = self::HEAD_PARENTHESIS;
        }

        return $this->after(self::STATEMENT_END, $offset + 1);
    }

    private function afterEquals(int $offset): int
    {
        if ('>' === ($this->literals->source[$offset + 1] ?? '')) {
            return $this->after(self::STATEMENT_END, $offset + 2);
        }

        return $this->after(self::OPERATOR, $offset + 1);
    }
}
