<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use ReflectionMethod;

/**
 * @implements Collector<InClassNode, non-empty-list<array{string, string, list<string>}>>
 */
final readonly class AbstractTraitSetterUseCollector implements Collector
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
        return InClassNode::class;
    }

    /**
     * @return non-empty-list<array{string, string, list<string>}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();
        $uses = [];

        // PHPStan does not visit an abstract trait method that an inherited method already implements, so TraitSetterUseCollector never sees that use; the using class records it here instead
        foreach ($this->traitsOf($class->getNativeReflection()) as $trait) {
            foreach ($trait->getMethods() as $method) {
                if ($this->isOwnAbstractSetter($trait, $method)) {
                    $uses[] = [$trait->getName(), $method->getName(), $this->ancestors->filesDeclaring($class, $method->getName())];
                }
            }
        }

        return [] === $uses ? null : $uses;
    }

    /**
     * @return list<ReflectionClass|ReflectionEnum>
     */
    private function traitsOf(ReflectionClass|ReflectionEnum $user): array
    {
        $traits = [];

        foreach ($user->getTraits() as $trait) {
            $traits = [...$traits, $trait, ...$this->traitsOf($trait)];
        }

        return $traits;
    }

    private function isOwnAbstractSetter(ReflectionClass|ReflectionEnum $trait, ReflectionMethod $method): bool
    {
        return $method->isAbstract() && $method->isPublic() && $trait->getName() === $method->getDeclaringClass()->getName() && $this->setters->isSetterName($method->getName());
    }
}
