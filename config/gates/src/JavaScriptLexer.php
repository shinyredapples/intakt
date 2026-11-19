<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a slash starts a pattern or divides depends on the token before it, the lexer keeps that token's kind and the open brackets for one source.
final class JavaScriptLexer
{
    private const string WHITESPACE = " \t\n\r\v\f";
    private const int OPERAND = 0;
    private const int OPERATOR = 1;
    private const int DOT = 2;
    private const int HEAD_KEYWORD = 3;
    private const int CLOSING_PARENTHESIS = 4;
    private const int STATEMENT_END = 5;
    private const array ENDS_AN_OPERAND = [self::OPERAND, self::CLOSING_PARENTHESIS];
    private const array OPENS_A_BLOCK = [self::OPERAND, self::CLOSING_PARENTHESIS, self::STATEMENT_END];
    private const int PARENTHESIS = 0;
    private const int HEAD_PARENTHESIS = 1;
    private const int BRACKET = 2;
    private const int BRACE = 3;
    private const int TEMPLATE_EXPRESSION = 4;
    private const int BLOCK = 5;
    private const array HEAD_KEYWORDS = ['if', 'while', 'for', 'with'];
    private const array BLOCK_KEYWORDS = ['do', 'else', 'try', 'finally'];
    private const array KEYWORDS_BEFORE_AN_OPERAND = ['return', 'typeof', 'case', 'default', 'in', 'of', 'new', 'delete', 'void', 'throw', 'instanceof', 'yield', 'await'];

    private readonly JavaScriptLiterals $literals;
    private int $previous = self::STATEMENT_END;

    /**
     * @var list<int>
     */
    private array $frames = [];

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
        $offset = 0;

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
            str_contains(self::WHITESPACE, $char) => $offset + strspn($this->literals->source, self::WHITESPACE, $offset),
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
            'await' === $word && self::HEAD_KEYWORD === $this->previous => $end,
            // Because `of` is a keyword only between the parentheses of a `for` head, elsewhere it names a variable.
            'of' === $word && self::HEAD_PARENTHESIS !== array_last($this->frames) => $this->after(self::OPERAND, $end),
            in_array($word, self::HEAD_KEYWORDS, true) => $this->after(self::HEAD_KEYWORD, $end),
            in_array($word, self::BLOCK_KEYWORDS, true) => $this->after(self::STATEMENT_END, $end),
            in_array($word, self::KEYWORDS_BEFORE_AN_OPERAND, true) => $this->after(self::OPERATOR, $end),
            default => $this->after(self::OPERAND, $end),
        };
    }

    private function afterPunctuator(int $offset, string $char): int
    {
        return match ($char) {
            '(' => $this->open(self::HEAD_KEYWORD === $this->previous ? self::HEAD_PARENTHESIS : self::PARENTHESIS, $offset),
            '[' => $this->open(self::BRACKET, $offset),
            // Because in ordinary code a brace after an operator opens an object and any other brace opens a block or a class or function body.
            '{' => $this->open(in_array($this->previous, self::OPENS_A_BLOCK, true) ? self::BLOCK : self::BRACE, $offset),
            ')', ']' => $this->afterClosingBracket($offset),
            '}' => $this->afterClosingBrace($offset),
            '.' => $this->afterDot($offset),
            '+', '-' => $this->afterPlusOrMinus($offset, $char),
            // Because the name after `#` is a private property, it is never a keyword, as after a `.`.
            '#' => $this->after(self::DOT, $offset + 1),
            ';' => $this->after(self::STATEMENT_END, $offset + 1),
            '=' => '>' === ($this->literals->source[$offset + 1] ?? '') ? $this->after(self::STATEMENT_END, $offset + 2) : $this->after(self::OPERATOR, $offset + 1),
            default => $this->after(self::OPERATOR, $offset + 1),
        };
    }

    private function open(int $frame, int $offset): int
    {
        $this->frames[] = $frame;

        return $this->after(self::BLOCK === $frame ? self::STATEMENT_END : self::OPERATOR, $offset + 1);
    }

    // Because the parenthesis that closes an `if`, `while`, `for` or `with` head ends no operand, a slash after it starts a pattern.
    private function afterClosingBracket(int $offset): int
    {
        $frame = self::TEMPLATE_EXPRESSION === array_last($this->frames) ? null : array_pop($this->frames);

        return $this->after(match ($frame) {
            self::HEAD_PARENTHESIS => self::STATEMENT_END,
            self::PARENTHESIS => self::CLOSING_PARENTHESIS,
            default => self::OPERAND,
        }, $offset + 1);
    }

    // Because a statement may follow the brace that closes a block, a slash after it starts a pattern, while after an object it divides.
    private function afterClosingBrace(int $offset): int
    {
        return match (array_pop($this->frames)) {
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

    private function after(int $previous, int $end): int
    {
        $this->previous = $previous;

        return $end;
    }
}
