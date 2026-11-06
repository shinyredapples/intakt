<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpCsFixer;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

// @Symfony keeps method_argument_space from splitting calls and omits arguments from trailing_comma_in_multiline, so the named-argument layout needs its own fixer.
final readonly class MultilineNamedArgumentsFixer implements FixerInterface
{
    private const string INDENT = '    ';

    public function getName(): string
    {
        return 'Intakt/multiline_named_arguments';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'A call with two or more named arguments puts every argument on its own line, with a trailing comma.',
            [new CodeSample("<?php\n\$report = render(\$issue, strict: true, limit: 3);\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(CT::T_NAMED_ARGUMENT_NAME);
    }

    public function isRisky(): bool
    {
        return false;
    }

    // Walks backwards so that tokens inserted into a call never shift the calls still to visit.
    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if ($tokens[$index]->isGivenKind(CT::T_ATTRIBUTE_CLOSE)) {
                $index = $tokens->findBlockStart(Tokens::BLOCK_TYPE_ATTRIBUTE, $index);
            } elseif ($tokens[$index]->equals('(')) {
                $this->splitWhenNamedTwice($tokens, $index);
            }
        }
    }

    // Ahead of statement_indentation (-3) because that fixer indents the inner lines of an argument this one moves.
    public function getPriority(): int
    {
        return 0;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    private function splitWhenNamedTwice(Tokens $tokens, int $openIndex): void
    {
        $closeIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openIndex);
        $topLevel = $this->topLevelTokens($tokens, $openIndex, $closeIndex);
        $namedCount = count(array_filter($topLevel, static fn (Token $token): bool => $token->isGivenKind(CT::T_NAMED_ARGUMENT_NAME)));

        if ($namedCount < 2) {
            return;
        }

        $indent = $this->lineIndent($tokens, $openIndex);
        $closeIndex = $this->ensureTrailingComma($tokens, $closeIndex);
        $this->breakLineBefore($tokens, $closeIndex, $indent);

        foreach (array_reverse(array_keys(array_filter($topLevel, static fn (Token $token): bool => $token->equals(',')))) as $commaIndex) {
            $this->breakLineAfter($tokens, $commaIndex, $indent.self::INDENT);
        }

        $this->breakLineAfter($tokens, $openIndex, $indent.self::INDENT);
    }

    /** @return array<int, Token> */
    private function topLevelTokens(Tokens $tokens, int $openIndex, int $closeIndex): array
    {
        $topLevel = [];

        for ($index = $openIndex + 1; $index < $closeIndex; ++$index) {
            $topLevel[$index] = $tokens[$index];
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);
            }
        }

        return $topLevel;
    }

    private function lineIndent(Tokens $tokens, int $openIndex): string
    {
        for ($index = $openIndex - 1; $index >= 0; --$index) {
            $content = $tokens[$index]->getContent();

            if (($tokens[$index]->isWhitespace() || $tokens[$index]->isGivenKind(T_OPEN_TAG)) && str_contains($content, "\n")) {
                return explode("\n", $content) |> array_last(...);
            }
        }

        return '';
    }

    private function ensureTrailingComma(Tokens $tokens, int $closeIndex): int
    {
        $lastIndex = $tokens->getPrevMeaningfulToken($closeIndex);

        if (null === $lastIndex || $tokens[$lastIndex]->equals(',')) {
            return $closeIndex;
        }

        $tokens->insertAt($lastIndex + 1, new Token(','));

        return $closeIndex + 1;
    }

    private function breakLineBefore(Tokens $tokens, int $closeIndex, string $indent): void
    {
        $lastIndex = $tokens->getPrevMeaningfulToken($closeIndex) ?? $closeIndex;

        if (!$this->hasLineBreakBetween($tokens, $lastIndex, $closeIndex)) {
            $tokens->ensureWhitespaceAtIndex($closeIndex - 1, 1, "\n".$indent);
        }
    }

    private function breakLineAfter(Tokens $tokens, int $index, string $indent): void
    {
        $nextIndex = $tokens->getNextMeaningfulToken($index) ?? $index;

        if (!$this->hasLineBreakBetween($tokens, $index, $nextIndex)) {
            $tokens->ensureWhitespaceAtIndex($index + 1, 0, "\n".$indent);
        }
    }

    // A comment between the boundary and the argument keeps its place when a line break already follows it; a line break inside a block comment does not count.
    private function hasLineBreakBetween(Tokens $tokens, int $fromIndex, int $toIndex): bool
    {
        for ($index = $fromIndex + 1; $index < $toIndex; ++$index) {
            $content = $tokens[$index]->getContent();

            if ($tokens[$index]->isWhitespace() ? str_contains($content, "\n") : str_ends_with($content, "\n")) {
                return true;
            }
        }

        return false;
    }
}
