<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<TraitUse, list<array{string, int, string, string, string, bool}>>
 */
final readonly class TraitAliasTestMethodCollector implements Collector
{
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

        // PHPUnit runs only the methods of a named TestCase subclass, as for the methods a class declares
        if (null === $class || $class->isAnonymous() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
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
        return array_values(array_filter($use->adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Alias && null !== $adaptation->newName));
    }

    /**
     * @return list<ReflectionMethod>
     */
    private function misnamedTestsOf(Alias $alias, TraitUse $use, ClassReflection $class): array
    {
        $native = $class->getNativeReflection();
        $methods = array_map($native->getMethod(...), array_filter([(string) $alias->newName, $alias->method->toString()], $native->hasMethod(...)));

        return array_values(array_filter($methods, fn (ReflectionMethod $method): bool => $this->comesFrom($method, $alias, $use) && !$this->isHidden($method, $use) && PhpUnitTestMethod::isRun($method) && !TestMethodName::readsAsSentence($method->getName())));
    }

    // because a method the class declares itself, or takes from another trait through insteadof, is judged where it is declared
    private function comesFrom(ReflectionMethod $method, Alias $alias, TraitUse $use): bool
    {
        $aliasName = $method->getBetterReflection()->getAliasName();
        $trait = $method->getBetterReflection()->getDeclaringClass()->getName();
        $sameName = null === $aliasName ? 0 === strcasecmp($method->getName(), $alias->method->toString()) : 0 === strcasecmp($aliasName, (string) $alias->newName);

        return $sameName && array_any($use->traits, static fn (Name $used): bool => 0 === strcasecmp($used->toString(), $trait)) && (null === $alias->trait || 0 === strcasecmp($alias->trait->toString(), $trait));
    }

    // because BetterReflection keeps the original name public when the class also renames it, while PHP hides it (upstream/phpstan-trait-alias-hidden-original-visibility/)
    private function isHidden(ReflectionMethod $method, TraitUse $use): bool
    {
        return null === $method->getBetterReflection()->getAliasName() && array_any($use->adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Alias && null === $adaptation->newName && 0 !== ($adaptation->newModifier & Modifiers::VISIBILITY_MASK) && 0 === ($adaptation->newModifier & Modifiers::PUBLIC) && 0 === strcasecmp($adaptation->method->toString(), $method->getName()));
    }

    private function mayBeProvider(ClassReflection $class, ReflectionMethod $method): bool
    {
        // because PHPUnit calls only a static provider and iterates what it returns
        return $method->isStatic() && !PhpUnitTestMethod::isMarked($method) && !$class->getNativeMethod($method->getName())->getOnlyVariant()->getNativeReturnType()->isIterable()->no();
    }
}
