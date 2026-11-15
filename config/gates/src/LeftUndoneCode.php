<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LeftUndoneCode
{
    private const array TEST_SKIPS = ['marktestskipped', 'marktestincomplete'];
    private const array CALL_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
    private const array CLASS_NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
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
        $tokens = LeftUndoneTokens::of($source);
        $findings = [];

        foreach ($tokens->indexes() as $index) {
            foreach ($detect($tokens, $index) as [$line, $lastLine, $finding]) {
                if (array_any(range($line, $lastLine), static fn (int $spanned): bool => isset($added[$spanned]))) {
                    $findings[$line] ??= $finding;
                }
            }
        }

        ksort($findings);

        return $findings;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function skipsAt(LeftUndoneTokens $tokens, int $index): array
    {
        if ($tokens->is($index, T_ATTRIBUTE)) {
            return $this->skipAttributesAt($tokens, $index);
        }

        // Because PHP resolves method names case insensitively, so MARKTESTSKIPPED() skips the test as well.
        $isCall = $tokens->is($index, T_STRING)
            && in_array(strtolower($tokens->text($index)), self::TEST_SKIPS, true)
            && $tokens->is($index - 1, self::CALL_OPERATORS)
            && $tokens->is($index + 1, '(');

        return $isCall ? $this->unreasonedSkip($tokens, $index, $tokens->text($index).' call') : [];
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function skipAttributesAt(LeftUndoneTokens $tokens, int $start): array
    {
        $skips = [];

        foreach ($tokens->attributeNames($start) as $index) {
            if ($tokens->is($index, self::CLASS_NAMES) && 1 === preg_match(self::SKIP_ATTRIBUTE, $tokens->text($index))) {
                $skips = [...$skips, ...$this->unreasonedSkip($tokens, $index, $tokens->text($index).' attribute')];
            }
        }

        return $skips;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function unreasonedSkip(LeftUndoneTokens $tokens, int $nameIndex, string $kind): array
    {
        $line = $tokens->line($nameIndex);
        // Because a string can run over several lines, while only its text on the line of the name is a reason there.
        $reasoned = array_any($tokens->stringsInArguments($nameIndex), static fn (array $string): bool => 1 === preg_match(self::REASON, explode("\n", $string[0])[$line - $string[1]] ?? ''));

        return $reasoned ? [] : [[$line, $tokens->line($tokens->argumentsEnd($nameIndex)), $kind.self::SKIP_HOW_TO_COMPLY]];
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function notImplementedThrowAt(LeftUndoneTokens $tokens, int $index): array
    {
        $isThrow = $tokens->is($index, T_THROW)
            && $tokens->is($index + 1, T_NEW)
            && $tokens->is($index + 2, self::CLASS_NAMES);

        if (!$isThrow) {
            return [];
        }

        $notImplemented = 1 === preg_match(self::NOT_IMPLEMENTED_CLASS, $tokens->text($index + 2))
            || array_any($tokens->stringsInArguments($index + 2), static fn (array $string): bool => 1 === preg_match(self::NOT_IMPLEMENTED, $string[0]));
        $line = $tokens->line($index);

        return $notImplemented && !$tokens->hasCommentOn($line, self::REASONED_THROW) ? [[$line, $tokens->line($tokens->argumentsEnd($index + 2)), self::THROW_HOW_TO_COMPLY]] : [];
    }
}
