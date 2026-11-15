<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneCode
{
    private const array TEST_SKIPS = ['markTestSkipped', 'markTestIncomplete'];
    private const string SKIP_ATTRIBUTE = '/^(?:.*\\\\)?(?:Skip|Incomplete)/';
    private const string NOT_IMPLEMENTED = '/\b(?:not (?:yet )?implemented|unimplemented)\b/i';
    private const string REASONED_THROW = '/^(?:\/\/|#)\s*(?:TODO|FIXME|XXX)'.LeftUndoneComments::ISSUE_REFERENCE.'.*[[:alpha:]]/i';
    private const string SKIP_HOW_TO_COMPLY = ' without a reason; pass a message naming the missing tool or outside condition, or make the test run';
    private const string THROW_HOW_TO_COMPLY = 'not-implemented throw left in the change; implement it, or open an issue and add // TODO(#<issue>): <reason> on the same line';

    /**
     * @param list<int> $addedLines
     *
     * @return array<int, string>
     */
    public function findingsOn(string $path, string $source, array $addedLines): array
    {
        $detect = match (true) {
            str_starts_with($path, 'tests/') => $this->skipAt(...),
            str_starts_with($path, 'src/') => $this->notImplementedThrowAt(...),
            default => null,
        };

        if (null === $detect) {
            return [];
        }

        $added = array_flip($addedLines);
        $tokens = $this->tokensWithLines($source);
        $findings = [];

        foreach ($tokens as $index => [, $line]) {
            $finding = isset($added[$line]) ? $detect($tokens, $index) : null;

            if (null !== $finding) {
                $findings[$line] ??= $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<array{PhpToken, int}>
     */
    private function tokensWithLines(string $source): array
    {
        $tokens = [];
        $line = 1;
        $counted = 0;

        foreach (PhpToken::tokenize($source) as $token) {
            // Because git numbers lines by line feeds only, while PHP also breaks a line at a lone carriage return.
            $line += substr_count($source, "\n", $counted, $token->pos - $counted);
            $counted = $token->pos;

            if (!$token->is([T_WHITESPACE, T_OPEN_TAG])) {
                $tokens[] = [$token, $line];
            }
        }

        return $tokens;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function skipAt(array $tokens, int $index): ?string
    {
        if ($this->isToken($tokens, $index, T_ATTRIBUTE)) {
            return $this->skipAttributeAt($tokens, $index);
        }

        $isCall = $this->isToken($tokens, $index, T_STRING)
            && in_array($tokens[$index][0]->text, self::TEST_SKIPS, true)
            && $this->isToken($tokens, $index - 1, [T_OBJECT_OPERATOR, T_DOUBLE_COLON]);

        return $isCall && !$this->hasReasonAfter($tokens, $index) ? $tokens[$index][0]->text.' call'.self::SKIP_HOW_TO_COMPLY : null;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function skipAttributeAt(array $tokens, int $start): ?string
    {
        foreach ($this->attributeNames($tokens, $start) as $index) {
            $name = $tokens[$index][0]->text;

            if ($this->isToken($tokens, $index, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && 1 === preg_match(self::SKIP_ATTRIBUTE, $name) && !$this->hasReasonAfter($tokens, $index)) {
                return $name.' attribute'.self::SKIP_HOW_TO_COMPLY;
            }
        }

        return null;
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

    private function depthChange(PhpToken $token): int
    {
        if ($token->is([T_ATTRIBUTE, '(', '['])) {
            return 1;
        }

        return $token->is([')', ']']) ? -1 : 0;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function hasReasonAfter(array $tokens, int $nameIndex): bool
    {
        return $this->isToken($tokens, $nameIndex + 1, '(')
            && $this->isToken($tokens, $nameIndex + 2, T_CONSTANT_ENCAPSED_STRING)
            && 1 === preg_match('/[[:alpha:]]/', $tokens[$nameIndex + 2][0]->text);
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function notImplementedThrowAt(array $tokens, int $index): ?string
    {
        $isThrow = $this->isToken($tokens, $index, T_THROW)
            && $this->isToken($tokens, $index + 1, T_NEW)
            && $this->isToken($tokens, $index + 3, '(')
            && $this->isToken($tokens, $index + 4, T_CONSTANT_ENCAPSED_STRING)
            && 1 === preg_match(self::NOT_IMPLEMENTED, $tokens[$index + 4][0]->text);

        return $isThrow && !$this->hasReasonedCommentOn($tokens, $tokens[$index][1]) ? self::THROW_HOW_TO_COMPLY : null;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function hasReasonedCommentOn(array $tokens, int $line): bool
    {
        return array_any($tokens, static fn (array $entry): bool => $entry[1] === $line && $entry[0]->is(T_COMMENT) && 1 === preg_match(self::REASONED_THROW, $entry[0]->text));
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
