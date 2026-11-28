<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<InClassMethodNode, array{string, int, string, string, string, bool}>
 */
final readonly class TestMethodCollector implements Collector
{
    // an acronym or a capital with the lowercase letters and digits after it is one word, so HTTPRequest and Its192Px are two words each; a run of capitals stays one word even when it starts with A or I, because only a dictionary could tell an article in AURL from the acronym ASCII
    private const string WORD = '[A-Z]{2,}(?![a-z])[0-9]*|[A-Z][a-z0-9]*';

    private const int SENTENCE_WORDS = 3;

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return array{string, int, string, string, string, bool}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        // PHPUnit runs only the methods of a named TestCase subclass, so an interface, a double or an anonymous class may name a method test*() freely
        if ($class->isInterface() || $class->isAnonymous() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $method = $class->getNativeReflection()->getMethod($node->getOriginalNode()->name->toString());

        return $this->isMisnamedTest($method) ? $this->location($node, $scope, $method) : null;
    }

    private function isMisnamedTest(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        return PhpUnitTestMethod::isRun($method) && (!str_starts_with($name, 'test') || !$this->readsAsSentence(substr($name, 4)));
    }

    /**
     * @return array{string, int, string, string, string, bool}
     */
    private function location(InClassMethodNode $node, Scope $scope, ReflectionMethod $method): array
    {
        $trait = $scope->getTraitReflection();
        $file = $trait?->getFileName() ?? $scope->getFile();

        return [$file, $node->getOriginalNode()->name->getStartLine(), $trait?->getName() ?? $node->getClassReflection()->getName(), $method->getDeclaringClass()->getName(), $method->getName(), $this->mayBeProvider($node, $method)];
    }

    private function readsAsSentence(string $words): bool
    {
        return 1 === preg_match('~^(?:'.self::WORD.')+$~', $words) && self::SENTENCE_WORDS <= preg_match_all('~'.self::WORD.'~', $words);
    }

    private function mayBeProvider(InClassMethodNode $node, ReflectionMethod $method): bool
    {
        // because PHPUnit calls only a static provider and iterates what it returns
        return $method->isStatic() && !PhpUnitTestMethod::isMarked($method) && !$node->getMethodReflection()->getNativeReturnType()->isIterable()->no();
    }
}
