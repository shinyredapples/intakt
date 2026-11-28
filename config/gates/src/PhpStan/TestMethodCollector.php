<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;

/**
 * @implements Collector<InClassMethodNode, array{string, int, string, string, string, bool}>
 */
final readonly class TestMethodCollector implements Collector
{
    private SentenceTestName $names;

    public function __construct()
    {
        $this->names = new SentenceTestName();
    }

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

        if (!PhpUnitTestMethod::isTestCase($class)) {
            return null;
        }

        $method = $class->getNativeReflection()->getMethod($node->getOriginalNode()->name->toString());

        return $this->isBoundHere($node, $scope, $method) && $this->names->isMisnamedTest($method) ? $this->location($node, $scope, $method) : null;
    }

    // because PHPStan also visits a trait method under each alias, which TraitAliasCollector reports at the alias, and a class that takes the name from another trait with insteadof no longer runs this declaration under it
    private function isBoundHere(InClassMethodNode $node, Scope $scope, ReflectionMethod $method): bool
    {
        $trait = $scope->getTraitReflection()?->getNativeReflection();

        if (null === $trait) {
            return true;
        }

        $line = $node->getOriginalNode()->getStartLine();

        return $trait->hasMethod($method->getName()) && $trait->getMethod($method->getName())->getStartLine() === $line && $method->getFileName() === $trait->getFileName() && $method->getStartLine() === $line;
    }

    /**
     * @return array{string, int, string, string, string, bool}
     */
    private function location(InClassMethodNode $node, Scope $scope, ReflectionMethod $method): array
    {
        $trait = $scope->getTraitReflection();
        $file = $trait?->getFileName() ?? $scope->getFile();

        return [$file, $node->getOriginalNode()->name->getStartLine(), $trait?->getName() ?? $node->getClassReflection()->getName(), $method->getDeclaringClass()->getName(), $method->getName(), $this->names->mayBeProvider($node->getClassReflection(), $method)];
    }
}
