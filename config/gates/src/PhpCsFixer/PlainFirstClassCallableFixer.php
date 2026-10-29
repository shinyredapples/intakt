<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpCsFixer;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

// native_function_invocation leaves first-class callables alone by design, so \strlen(...) needs its own fixer.
final readonly class PlainFirstClassCallableFixer implements FixerInterface
{
    public function getName(): string
    {
        return 'Intakt/plain_first_class_callable';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'A first-class callable of a global function is written plain, without a leading backslash.',
            [new CodeSample("<?php\nnamespace Sample;\n\$length = \\strlen(...);\n")],
            null,
            'A namespaced function of the same name now takes precedence over the global one.',
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAllTokenKindsFound([CT::T_FIRST_CLASS_CALLABLE, T_NS_SEPARATOR]);
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token->isGivenKind(CT::T_FIRST_CLASS_CALLABLE)) {
                $this->removeLeadingBackslash($tokens, $index);
            }
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    private function removeLeadingBackslash(Tokens $tokens, int $callableIndex): void
    {
        $separatorIndex = $this->leadingBackslashOfGlobalFunction($tokens, $callableIndex);

        if (null !== $separatorIndex) {
            $tokens->clearTokenAndMergeSurroundingWhitespace($separatorIndex);
        }
    }

    private function leadingBackslashOfGlobalFunction(Tokens $tokens, int $callableIndex): ?int
    {
        $openIndex = $tokens->getPrevMeaningfulToken($callableIndex);
        $nameIndex = null === $openIndex ? null : $tokens->getPrevMeaningfulToken($openIndex);

        if (null === $nameIndex || !$tokens[$nameIndex]->isGivenKind(T_STRING)) {
            return null;
        }

        $separatorIndex = $tokens->getPrevMeaningfulToken($nameIndex);

        if (null === $separatorIndex || !$tokens[$separatorIndex]->isGivenKind(T_NS_SEPARATOR)) {
            return null;
        }

        // Skipped when a segment precedes the backslash (Sample\run, namespace\run), because that names a namespaced function.
        $beforeIndex = $tokens->getPrevMeaningfulToken($separatorIndex);

        return null !== $beforeIndex && $tokens[$beforeIndex]->isGivenKind([T_STRING, T_NAMESPACE, CT::T_NAMESPACE_OPERATOR]) ? null : $separatorIndex;
    }
}
