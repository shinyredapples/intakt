<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;

/**
 * @implements Collector<TraitUse, non-empty-list<array{string, int, string, string, string, bool, string}>>
 */
final readonly class TraitAliasCollector implements Collector
{
    private SentenceTestName $names;

    public function __construct()
    {
        $this->names = new SentenceTestName();
    }

    public function getNodeType(): string
    {
        return TraitUse::class;
    }

    // PHPStan visits a trait's own `use` once inside each class that uses the trait, so an alias a wrapper trait declares is judged with the methods each using class exposes
    /**
     * @return non-empty-list<array{string, int, string, string, string, bool, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || !PhpUnitTestMethod::isTestCase($class)) {
            return null;
        }

        $declarer = $scope->getTraitReflection() ?? $class;
        $records = [];

        foreach ($node->adaptations as $alias) {
            if ($alias instanceof Alias && $alias->newName instanceof Identifier) {
                $records = [...$records, ...$this->misnamedNamesOf($alias, $class, $declarer)];
            }
        }

        return [] === $records ? null : $records;
    }

    /**
     * @return list<array{string, int, string, string, string, bool, string}>
     */
    private function misnamedNamesOf(Alias $alias, ClassReflection $class, ClassReflection $declarer): array
    {
        $native = $class->getNativeReflection();
        $exposed = (string) $alias->newName?->toString();
        $records = [];

        if ($native->hasMethod($exposed) && $this->names->isMisnamedTest($native->getMethod($exposed))) {
            $records[] = [...$this->location($alias, $declarer), ...$this->judged($class, $native->getMethod($exposed)), ''];
        }

        $original = $this->originalStillBound($alias, $class, $declarer);

        if ($original instanceof ReflectionMethod && $this->names->isMisnamedTest($original)) {
            $records[] = [...$this->location($alias, $declarer), ...$this->judged($class, $original), (string) $original->getFileName()];
        }

        return $records;
    }

    /**
     * @return array{string, int, string}
     */
    private function location(Alias $alias, ClassReflection $declarer): array
    {
        return [(string) $declarer->getFileName(), $alias->getStartLine(), $declarer->getName()];
    }

    /**
     * @return array{string, string, bool}
     */
    private function judged(ClassReflection $class, ReflectionMethod $method): array
    {
        return [$method->getDeclaringClass()->getName(), $method->getName(), $this->names->mayBeProvider($class, $method)];
    }

    // because the class still runs the trait method under its own name unless it takes that name from another trait with insteadof
    private function originalStillBound(Alias $alias, ClassReflection $class, ClassReflection $declarer): ?ReflectionMethod
    {
        $name = $alias->method->toString();
        $declared = $this->traitDeclaring($alias, $declarer)?->getNativeReflection()->getMethod($name);
        $native = $class->getNativeReflection();

        if (null === $declared || !$native->hasMethod($name)) {
            return null;
        }

        $bound = $native->getMethod($name);

        return $bound->getFileName() === $declared->getFileName() && $bound->getStartLine() === $declared->getStartLine() ? $bound : null;
    }

    // because PHP resolves an alias without a trait name against every trait the declaring class or trait uses
    private function traitDeclaring(Alias $alias, ClassReflection $declarer): ?ClassReflection
    {
        $method = $alias->method->toString();
        $named = $alias->trait instanceof Name ? $alias->trait->toString() : null;

        return array_find($declarer->getTraits(), static fn (ClassReflection $trait): bool => (null === $named || 0 === strcasecmp($named, $trait->getName())) && $trait->getNativeReflection()->hasMethod($method));
    }
}
