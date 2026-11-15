<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneTokens
{
    private const array STRINGS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
    private const array COMMENTS = [T_COMMENT, T_DOC_COMMENT];

    /**
     * @param list<array{PhpToken, int}> $code
     * @param list<array{PhpToken, int}> $comments
     */
    private function __construct(
        private array $code,
        private array $comments,
    ) {
    }

    public static function of(string $source): self
    {
        $code = [];
        $comments = [];
        $line = 1;
        $counted = 0;

        foreach (PhpToken::tokenize($source) as $token) {
            // Because git numbers lines by line feeds only, while PHP also breaks a line at a lone carriage return.
            $line += substr_count($source, "\n", $counted, $token->pos - $counted);
            $counted = $token->pos;

            if ($token->is(self::COMMENTS)) {
                $comments[] = [$token, $line];
            } elseif (!$token->is([T_WHITESPACE, T_OPEN_TAG])) {
                $code[] = [$token, $line];
            }
        }

        return new self($code, $comments);
    }

    /**
     * @return list<int>
     */
    public function indexes(): array
    {
        return array_keys($this->code);
    }

    /**
     * @param int|string|list<int|string> $kinds
     */
    public function is(int $index, int|string|array $kinds): bool
    {
        return isset($this->code[$index]) && $this->code[$index][0]->is($kinds);
    }

    public function text(int $index): string
    {
        return $this->code[$index][0]->text;
    }

    public function line(int $index): int
    {
        return $this->code[$index][1];
    }

    public function argumentsEnd(int $nameIndex): int
    {
        if (!$this->is($nameIndex + 1, '(')) {
            return $nameIndex;
        }

        $depth = 0;

        for ($index = $nameIndex + 1; $index < count($this->code); ++$index) {
            $depth += $this->depthChange($index);

            if (0 === $depth) {
                return $index;
            }
        }

        return count($this->code) - 1;
    }

    /**
     * @return list<int>
     */
    public function attributeNames(int $start): array
    {
        $names = [];
        $depth = 0;
        $startsName = true;

        for ($index = $start + 1; $index < count($this->code); ++$index) {
            if ($startsName) {
                $names[] = $index;
            }

            if (0 === $depth && $this->is($index, ']')) {
                break;
            }

            $depth += $this->depthChange($index);
            $startsName = 0 === $depth && $this->is($index, ',');
        }

        return $names;
    }

    /**
     * @return list<array{string, int}>
     */
    public function stringsInArguments(int $nameIndex): array
    {
        $arguments = array_slice($this->code, $nameIndex + 1, $this->argumentsEnd($nameIndex) - $nameIndex);
        $strings = array_filter($arguments, static fn (array $entry): bool => $entry[0]->is(self::STRINGS));

        return array_values(array_map(static fn (array $entry): array => [$entry[0]->text, $entry[1]], $strings));
    }

    public function hasCommentOn(int $line, string $pattern): bool
    {
        return array_any($this->comments, static fn (array $entry): bool => $entry[1] === $line && 1 === preg_match($pattern, $entry[0]->text));
    }

    private function depthChange(int $index): int
    {
        if ($this->is($index, [T_ATTRIBUTE, '(', '['])) {
            return 1;
        }

        return $this->is($index, [')', ']']) ? -1 : 0;
    }
}
