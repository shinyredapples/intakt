<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<TraitUse, list<array{string, int, string, string, string, bool}>>
 */
final readonly class TraitAliasTestMethodCollector implements Collector
{
    public function __construct(private ReflectionProvider $reflections)
    {
    }

    public function getNodeType(): string
    {
        return TraitUse::class;
    }

    /**
     * @return list<array{string, int, string, string, string, bool}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        // because PHPUnit runs only the methods of a named TestCase subclass, so an alias elsewhere may name a method test*() freely
        if (!$class instanceof ClassReflection || $class->isAnonymous() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $trait = $scope->getTraitReflection();
        $records = [];

        foreach ($this->renamingAliases($node) as $alias) {
            foreach ($this->misnamedTestsOf($alias, $node, $class) as $method) {
                // keyed by name because a class that renames one method twice still runs its original name once
                $records[strtolower($method->getName())] ??= [$trait?->getFileName() ?? $scope->getFile(), $alias->getStartLine(), $trait?->getName() ?? $class->getName(), $class->getName(), $method->getName(), $this->mayBeProvider($class, $method)];
            }
        }

        return [] === $records ? null : array_values($records);
    }

    /**
     * @return list<Alias>
     */
    private function renamingAliases(TraitUse $use): array
    {
        return array_values(array_filter($use->adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Alias && $adaptation->newName instanceof Identifier));
    }

    /**
     * @return list<ReflectionMethod>
     */
    private function misnamedTestsOf(Alias $alias, TraitUse $use, ClassReflection $class): array
    {
        $native = $class->getNativeReflection();
        $methods = array_map($native->getMethod(...), array_filter([(string) $alias->newName, $alias->method->toString()], $native->hasMethod(...)));

        return array_values(array_filter($methods, fn (ReflectionMethod $method): bool => $this->comesFrom($method, $alias, $use) && $this->isPublic($method, $alias, $use) && PhpUnitTestMethod::isMarkedOrNamedAsTest($method) && !TestMethodName::readsAsSentence($method->getName())));
    }

    // because a method the class declares itself, or takes from another trait through insteadof, is judged where it is declared
    private function comesFrom(ReflectionMethod $method, Alias $alias, TraitUse $use): bool
    {
        $aliasName = $method->getBetterReflection()->getAliasName();
        $sameName = null === $aliasName ? 0 === strcasecmp($method->getName(), $alias->method->toString()) : 0 === strcasecmp($aliasName, (string) $alias->newName);

        return $sameName && array_any($use->traits, fn (Name $used): bool => $this->provides($used, $method)) && (!$alias->trait instanceof Name || $this->provides($alias->trait, $method));
    }

    // because BetterReflection mixes the visibility changes of the original name and its aliases when a class renames a method (upstream/phpstan-trait-alias-hidden-original-visibility/), the use block and the trait's declaration decide, as in PHP
    private function isPublic(ReflectionMethod $method, Alias $alias, TraitUse $use): bool
    {
        $changes = null === $method->getBetterReflection()->getAliasName()
            ? array_filter($use->adaptations, fn (Node $adaptation): bool => $adaptation instanceof Alias && !$adaptation->newName instanceof Identifier && 0 === strcasecmp($adaptation->method->toString(), $method->getName()) && (!$adaptation->trait instanceof Name || $this->provides($adaptation->trait, $method)))
            : [$alias];
        $change = array_last(array_filter($changes, static fn (Alias $change): bool => 0 !== ($change->newModifier & Modifiers::VISIBILITY_MASK)));

        return $change instanceof Alias ? 0 !== ($change->newModifier & Modifiers::PUBLIC) : $this->isDeclaredPublic($method, $alias);
    }

    private function isDeclaredPublic(ReflectionMethod $method, Alias $alias): bool
    {
        $trait = $this->reflections->getClass($method->getBetterReflection()->getDeclaringClass()->getName())->getNativeReflection();

        return $trait->hasMethod($alias->method->toString()) && $trait->getMethod($alias->method->toString())->isPublic();
    }

    // because a trait named in the use block also passes on the methods of the traits it uses itself
    private function provides(Name $trait, ReflectionMethod $method): bool
    {
        $declaring = $method->getBetterReflection()->getDeclaringClass()->getName();

        return $this->reflections->hasClass($trait->toString()) && array_any($this->traitsWithin($this->reflections->getClass($trait->toString())), static fn (string $name): bool => 0 === strcasecmp($name, $declaring));
    }

    /**
     * @return list<string>
     */
    private function traitsWithin(ClassReflection $trait): array
    {
        return [$trait->getName(), ...array_merge(...array_map($this->traitsWithin(...), array_values($trait->getTraits())))];
    }

    private function mayBeProvider(ClassReflection $class, ReflectionMethod $method): bool
    {
        // because PHPUnit calls only a static provider and iterates what it returns
        return $method->isStatic() && !PhpUnitTestMethod::isMarked($method) && !$class->getNativeMethod($method->getName())->getOnlyVariant()->getNativeReturnType()->isIterable()->no();
    }
}
