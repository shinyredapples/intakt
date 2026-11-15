<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneCode
{
    private const array TEST_SKIPS = ['markTestSkipped', 'markTestIncomplete'];
    private const string SKIP_ATTRIBUTE = '/^(?:.*\\\\)?(?:Skip|Incomplete)/';
    private const string NOT_IMPLEMENTED = '/\b(?:not (?:yet )?implemented|unimplemented)\b/i';
    private const string REASONED_THROW = '/^\/\/\s*(?:TODO|FIXME|XXX)'.LeftUndoneComments::ISSUE_REFERENCE.'.*[[:alpha:]]/i';
    private const string TEST_REASON = ' without a reason; pass a message naming the missing tool or outside condition, or make the test run';
    private const string THROW_HOW_TO_COMPLY = ' left in the change; implement it, or open an issue and add // TODO(#<issue>): <reason> on the same line';

    /**
     * @param list<int> $addedLines
     *
     * @return array<int, string>
     */
    public function findingsOn(string $path, string $source, array $addedLines): array
    {
        $inTests = str_starts_with($path, 'tests/');
        $inSource = str_starts_with($path, 'src/');

        if (!$inTests && !$inSource) {
            return [];
        }

        $added = array_flip($addedLines);
        $tokens = $this->tokensWithLines($source);
        $findings = [];

        foreach ($tokens as $index => [$token, $line]) {
            if (!isset($added[$line])) {
                continue;
            }

            $finding = $inTests ? $this->skipAt($tokens, $index) : $this->notImplementedThrowAt($tokens, $index);

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
        $token = $tokens[$index][0];

        if ($token->is(T_ATTRIBUTE) || $token->is(',')) {
            $name = $tokens[$index + 1][0] ?? null;

            return $this->isSkipAttribute($tokens, $index, $name) ? $this->withoutReason($name, 'attribute', $tokens, $index + 2) : null;
        }

        $isCall = $token->is(T_STRING) && in_array($token->text, self::TEST_SKIPS, true) && ($tokens[$index - 1][0] ?? null)?->is([T_OBJECT_OPERATOR, T_DOUBLE_COLON]);

        return $isCall ? $this->withoutReason($token, 'call', $tokens, $index + 1) : null;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function isSkipAttribute(array $tokens, int $index, ?PhpToken $name): bool
    {
        if (null === $name || !$name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        // Because a comma only starts a name inside an attribute group, never in an argument list or array.
        return $tokens[$index][0]->is(T_ATTRIBUTE) || $this->isInsideAttributeGroup($tokens, $index);
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function isInsideAttributeGroup(array $tokens, int $index): bool
    {
        $depth = 0;

        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i][0];

            if ($token->is([')', ']'])) {
                ++$depth;
            }

            if ($token->is(['(', '[']) && 0 === $depth--) {
                return false;
            }

            if ($token->is(T_ATTRIBUTE) && 0 === $depth) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function withoutReason(PhpToken $name, string $kind, array $tokens, int $next): ?string
    {
        if ('attribute' === $kind && 1 !== preg_match(self::SKIP_ATTRIBUTE, $name->text)) {
            return null;
        }

        $first = $tokens[$next + 1][0] ?? null;
        $hasReason = ($tokens[$next][0] ?? null)?->is('(') && $first?->is(T_CONSTANT_ENCAPSED_STRING) && 1 === preg_match('/[[:alpha:]]/', $first->text);

        return $hasReason ? null : $name->text.' '.$kind.self::TEST_REASON;
    }

    /**
     * @param list<array{PhpToken, int}> $tokens
     */
    private function notImplementedThrowAt(array $tokens, int $index): ?string
    {
        [$throw, $line] = $tokens[$index];
        $message = $tokens[$index + 4][0] ?? null;
        $isThrow = $throw->is(T_THROW) && ($tokens[$index + 1][0] ?? null)?->is(T_NEW) && ($tokens[$index + 3][0] ?? null)?->is('(');

        if (!$isThrow || !$message?->is(T_CONSTANT_ENCAPSED_STRING) || 1 !== preg_match(self::NOT_IMPLEMENTED, $message->text)) {
            return null;
        }

        $reasoned = array_any($tokens, fn (array $other): bool => $other[1] === $line && $other[0]->is(T_COMMENT) && 1 === preg_match(self::REASONED_THROW, $other[0]->text));

        return $reasoned ? null : 'not-implemented throw'.self::THROW_HOW_TO_COMPLY;
    }
}
