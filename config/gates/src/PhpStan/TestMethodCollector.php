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

        // because PHPStan visits a trait method a class renames only under its last alias, in the trait's file; TraitAliasTestMethodCollector judges each alias and the original name at the alias instead
        if ($node->getOriginalNode()->hasAttribute('originalTraitMethodName')) {
            return null;
        }

        $method = $class->getNativeReflection()->getMethod($node->getOriginalNode()->name->toString());

        return $this->isMisnamedTest($method) ? $this->location($node, $scope, $method) : null;
    }

    private function isMisnamedTest(ReflectionMethod $method): bool
    {
        return PhpUnitTestMethod::isRun($method) && !TestMethodName::readsAsSentence($method->getName());
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

    private function mayBeProvider(InClassMethodNode $node, ReflectionMethod $method): bool
    {
        // because PHPUnit calls only a static provider and iterates what it returns
        return $method->isStatic() && !PhpUnitTestMethod::isMarked($method) && !$node->getMethodReflection()->getNativeReturnType()->isIterable()->no();
    }
}
