<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because JavaScript reads a slash as division or as the start of a regular expression by the code before it, the scanner carries that code's context.
final readonly class JavaScriptContext
{
    private const string WORD_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';
    // Because after these words an expression starts, so a slash there opens a regular expression, while after any other word it divides.
    private const array KEYWORDS_BEFORE_AN_EXPRESSION = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];
    // Because these words start a statement, a brace after them opens a block and not an object literal.
    private const array KEYWORDS_BEFORE_A_BLOCK = ['do', 'else'];
    // Because the parentheses after these words hold a condition, a slash after them starts the statement's body.
    private const array KEYWORDS_BEFORE_A_CONDITION = ['if', 'while', 'for', 'with'];
    private const string VALUE = '""';

    /**
     * @param list<JavaScriptSlash> $closings what a slash after the closing bracket of each open bracket means, innermost last
     */
    public function __construct(
        private JavaScriptSlash $slash = JavaScriptSlash::Regex,
        private array $closings = [],
        private string $before = '',
    ) {
    }

    public function slashStartsARegex(): bool
    {
        return JavaScriptSlash::Regex === $this->slash;
    }

    public function isOutermost(): bool
    {
        return [] === $this->closings;
    }

    public function afterCode(string $code): self
    {
        return '' === $code ? $this : new self($this->slashAfter($code), $this->closings, $code);
    }

    public function afterValue(): self
    {
        return new self(JavaScriptSlash::Division, $this->closings, self::VALUE);
    }

    public function afterDivision(): self
    {
        return new self(JavaScriptSlash::Regex, $this->closings, '/');
    }

    public function afterOpening(string $opening): self
    {
        return new self(JavaScriptSlash::Regex, [...$this->closings, $this->slashAfterClosing($opening)], $opening);
    }

    public function afterClosing(string $closing): self
    {
        return new self(array_last($this->closings) ?? JavaScriptSlash::Division, array_slice($this->closings, 0, -1), $closing);
    }

    // Because a postfix increment or decrement ends an operand, a slash after it divides.
    private function slashAfter(string $code): JavaScriptSlash
    {
        $word = $this->wordAtEnd($code);

        if ('' === $word) {
            return str_ends_with($code, '++') || str_ends_with($code, '--') ? JavaScriptSlash::Division : JavaScriptSlash::Regex;
        }

        return $this->isKeyword($code, $word, self::KEYWORDS_BEFORE_AN_EXPRESSION) ? JavaScriptSlash::Regex : JavaScriptSlash::Division;
    }

    private function slashAfterClosing(string $opening): JavaScriptSlash
    {
        $word = $this->wordAtEnd($this->before);

        return match ($opening) {
            '(' => $this->isKeyword($this->before, $word, self::KEYWORDS_BEFORE_A_CONDITION) ? JavaScriptSlash::Regex : JavaScriptSlash::Division,
            '{' => $this->opensBlock($word) ? JavaScriptSlash::Regex : JavaScriptSlash::Division,
            default => JavaScriptSlash::Division,
        };
    }

    // Because a brace where an expression is expected opens an object literal, which a slash then divides, while any other brace opens a block.
    private function opensBlock(string $word): bool
    {
        if ('' === $this->before || str_ends_with($this->before, '=>') || str_contains(';{})', $this->before[-1])) {
            return true;
        }

        return '' !== $word && (in_array($word, self::KEYWORDS_BEFORE_A_BLOCK, true) || !in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true));
    }

    // Because a keyword after a dot is only a property name, it does not count as the keyword.
    /**
     * @param list<string> $keywords
     */
    private function isKeyword(string $code, string $word, array $keywords): bool
    {
        return in_array($word, $keywords, true) && '.' !== substr($code, -strlen($word) - 1, 1);
    }

    // Because identifiers may hold any Unicode letter, every byte of a multibyte character counts as part of a word.
    private function wordAtEnd(string $code): string
    {
        $start = strlen($code);

        while ($start > 0 && (ord($code[$start - 1]) >= 0x80 || str_contains(self::WORD_CHARACTERS, $code[$start - 1]))) {
            --$start;
        }

        return substr($code, $start);
    }
}
