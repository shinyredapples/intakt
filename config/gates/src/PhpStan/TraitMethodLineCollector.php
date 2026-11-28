<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InTraitNode;

/**
 * @implements Collector<InTraitNode, non-empty-list<array{string, string, int}>>
 */
final readonly class TraitMethodLineCollector implements Collector
{
    public function getNodeType(): string
    {
        return InTraitNode::class;
    }

    // PHPStan visits an aliased trait method only under its last alias, whose name carries the alias line, so the line where the trait names the method comes from the trait as written
    /**
     * @return non-empty-list<array{string, string, int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!PhpUnitTestMethod::isTestCase($node->getImplementingClassReflection())) {
            return null;
        }

        $trait = $node->getTraitReflection()->getName();
        $lines = array_map(static fn (ClassMethod $method): array => [$trait, $method->name->toString(), $method->name->getStartLine()], $node->getOriginalNode()->getMethods());

        return [] === $lines ? null : $lines;
    }
}
