<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<Trait_, non-empty-list<array{string, string, int}>>
 */
final readonly class TraitSetterDeclarationCollector implements Collector
{
    private SetterMethod $setters;

    public function __construct()
    {
        $this->setters = new SetterMethod();
    }

    public function getNodeType(): string
    {
        return Trait_::class;
    }

    /**
     * @return non-empty-list<array{string, string, int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $setters = array_filter($node->getMethods(), $this->setters->isPublicSetter(...));
        $declarations = array_map(static fn (ClassMethod $method): array => [(string) $node->namespacedName, $method->name->toString(), $method->getStartLine()], array_values($setters));

        return [] === $declarations ? null : $declarations;
    }
}
