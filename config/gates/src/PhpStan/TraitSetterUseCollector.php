<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;

/**
 * @implements Collector<ClassMethod, array{string, string, list<string>}>
 */
final readonly class TraitSetterUseCollector implements Collector
{
    private SetterMethod $setters;

    private AncestorDeclarations $ancestors;

    public function __construct()
    {
        $this->setters = new SetterMethod();
        $this->ancestors = new AncestorDeclarations();
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return array{string, string, list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        // PHPStan analyzes a trait's method once inside each class that uses the trait, so each visit here is one use, with that class's ancestors
        if (!$scope->isInTrait() || !$class instanceof ClassReflection || !$this->setters->isPublicSetter($node)) {
            return null;
        }

        $name = $node->name->toString();

        return [$scope->getTraitReflection()->getName(), $name, $this->ancestors->filesDeclaring($class, $name)];
    }
}
