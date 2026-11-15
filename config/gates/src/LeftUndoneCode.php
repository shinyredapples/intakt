<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneCode
{
    private const array TEST_SKIPS = ['marktestskipped', 'marktestincomplete'];
    private const array CALL_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
    private const array CLASS_NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    private const array STRINGS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
    private const array COMMENTS = [T_COMMENT, T_DOC_COMMENT];
    private const string SKIP_ATTRIBUTE = '/^(?:.*\\\\)?(?:Skip|Incomplete)/i';
    private const string NOT_IMPLEMENTED = '/\b(?:not (?:yet )?implemented|unimplemented)\b/i';
    private const string NOT_IMPLEMENTED_CLASS = '/(?:NotImplemented|Unimplemented)\w*$/i';
    private const string REASON = '/[[:alpha:]]/';
    private const string REASONED_THROW = '/^(?:\/\/|#)\s*(?:TODO|FIXME|XXX)'.LeftUndoneComments::ISSUE_REFERENCE.'.*[[:alpha:]]/i';
    private const string SKIP_HOW_TO_COMPLY = ' without a reason; pass a message naming the missing tool or outside condition on the same line, or make the test run';
    private const string THROW_HOW_TO_COMPLY = 'not-implemented throw left in the change; implement it, or open an issue and add // TODO(#<issue>): <reason> on the same line';

    /**
     * @param list<int> $addedLines
     *
     * @return array<int, string>
     */
    public function findingsOn(string $path, string $source, array $addedLines): array
    {
        $detect = match (true) {
            str_starts_with($path, 'tests/') => $this->skipsAt(...),
            str_starts_with($path, 'src/') => $this->notImplementedThrowAt(...),
            default => null,
        };

        if (null === $detect) {
            return [];
        }

        $added = array_flip($addedLines);
        [$tokens, $comments] = $this->tokensWithLines($source);
        $findings = [];

        foreach (array_keys($tokens) as $index) {
            foreach ($detect($tokens, $comments, $index) as [$line, $lastLine, $finding]) {
                if (array_any(range($line, $lastLine), static fn (int $spanned): bool => isset($added[$spanned]))) {
                    $findings[$line] ??= $finding;
                }
            }
        }

        ksort($findings);

        return $findings;
    }

    /**
     * @return array{list<array{PhpToken, int}>, list<array{PhpToken, int}>}
     */
    private function tokensWithLines(string $source): array
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

        return [$code, $comments];
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     * @param list<array{PhpToken, int}> $comments
     *
     * @return list<array{int, int, string}>
     */
    private function skipsAt(array $tokens, array $comments, int $index): array
    {
        if ($this->isToken($tokens, $index, T_ATTRIBUTE)) {
            return $this->skipAttributesAt($tokens, $index);
        }

        // Because PHP resolves method names case insensitively, so MARKTESTSKIPPED() skips the test as well.
        $isCall = $this->isToken($tokens, $index, T_STRING)
            && in_array(strtolower($tokens[$index][0]->text), self::TEST_SKIPS, true)
            && $this->isToken($tokens, $index - 1, self::CALL_OPERATORS);

        return $isCall ? $this->unreasonedSkip($tokens, $index, $tokens[$index][0]->text.' call') : [];
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     *
     * @return list<array{int, int, string}>
     */
    private function skipAttributesAt(array $tokens, int $start): array
    {
        $skips = [];

        foreach ($this->attributeNames($tokens, $start) as $index) {
            $name = $tokens[$index][0]->text;

            if ($this->isToken($tokens, $index, self::CLASS_NAMES) && 1 === preg_match(self::SKIP_ATTRIBUTE, $name)) {
                $skips = [...$skips, ...$this->unreasonedSkip($tokens, $index, $name.' attribute')];
            }
        }

        return $skips;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     *
     * @return list<int>
     */
    private function attributeNames(array $tokens, int $start): array
    {
        $names = [];
        $depth = 0;
        $startsName = true;

        for ($index = $start + 1; $index < count($tokens); ++$index) {
            if ($startsName) {
                $names[] = $index;
            }

            if (0 === $depth && $this->isToken($tokens, $index, ']')) {
                break;
            }

            $depth += $this->depthChange($tokens[$index][0]);
            $startsName = 0 === $depth && $this->isToken($tokens, $index, ',');
        }

        return $names;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     *
     * @return list<array{int, int, string}>
     */
    private function unreasonedSkip(array $tokens, int $nameIndex, string $kind): array
    {
        $line = $tokens[$nameIndex][1];
        $last = $this->argumentsEnd($tokens, $nameIndex);
        $reasoned = array_any(
            array_slice($tokens, $nameIndex + 1, $last - $nameIndex),
            fn (array $entry): bool => $entry[1] === $line && $this->stringMatches($entry[0], self::REASON),
        );

        return $reasoned ? [] : [[$line, $tokens[$last][1], $kind.self::SKIP_HOW_TO_COMPLY]];
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     * @param list<array{PhpToken, int}> $comments
     *
     * @return list<array{int, int, string}>
     */
    private function notImplementedThrowAt(array $tokens, array $comments, int $index): array
    {
        $isThrow = $this->isToken($tokens, $index, T_THROW)
            && $this->isToken($tokens, $index + 1, T_NEW)
            && $this->isToken($tokens, $index + 2, self::CLASS_NAMES);

        if (!$isThrow || !$this->createsNotImplemented($tokens, $index + 2)) {
            return [];
        }

        $line = $tokens[$index][1];

        return $this->hasReasonedCommentOn($comments, $line) ? [] : [[$line, $tokens[$this->argumentsEnd($tokens, $index + 2)][1], self::THROW_HOW_TO_COMPLY]];
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function createsNotImplemented(array $tokens, int $classIndex): bool
    {
        if (1 === preg_match(self::NOT_IMPLEMENTED_CLASS, $tokens[$classIndex][0]->text)) {
            return true;
        }

        $arguments = array_slice($tokens, $classIndex + 1, $this->argumentsEnd($tokens, $classIndex) - $classIndex);

        return array_any($arguments, fn (array $entry): bool => $this->stringMatches($entry[0], self::NOT_IMPLEMENTED));
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function argumentsEnd(array $tokens, int $nameIndex): int
    {
        if (!$this->isToken($tokens, $nameIndex + 1, '(')) {
            return $nameIndex;
        }

        $depth = 0;

        for ($index = $nameIndex + 1; $index < count($tokens); ++$index) {
            $depth += $this->depthChange($tokens[$index][0]);

            if (0 === $depth) {
                return $index;
            }
        }

        return count($tokens) - 1;
    }

    private function depthChange(PhpToken $token): int
    {
        if ($token->is([T_ATTRIBUTE, '(', '['])) {
            return 1;
        }

        return $token->is([')', ']']) ? -1 : 0;
    }

    private function stringMatches(PhpToken $token, string $pattern): bool
    {
        return $token->is(self::STRINGS) && 1 === preg_match($pattern, $token->text);
    }

    /**
     * @param list<array{PhpToken, int}> $comments
     */
    private function hasReasonedCommentOn(array $comments, int $line): bool
    {
        return array_any($comments, static fn (array $entry): bool => $entry[1] === $line && 1 === preg_match(self::REASONED_THROW, $entry[0]->text));
    }

    /**
     * @param list<array{PhpToken, int}>  $tokens
     * @param int|string|list<int|string> $kinds
     */
    private function isToken(array $tokens, int $index, int|string|array $kinds): bool
    {
        return isset($tokens[$index]) && $tokens[$index][0]->is($kinds);
    }
}
