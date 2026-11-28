<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
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

        $owner = $scope->getTraitReflection() ?? $class;
        $composition = new TraitComposition($this->reflections);
        $exposed = $composition->takenFromTraits($class);
        $declared = $composition->takenFromTraits($owner);
        $records = [];

        foreach ($this->renamingAliases($node) as $alias) {
            foreach ($this->namesRunUnder($alias, $owner, $declared) as $name => $identity) {
                $method = $exposed[strtolower($name)] ?? null;

                // keyed by name because a class that renames one method twice still runs its original name once
                if ($method instanceof TraitMethod && $method->identity() === $identity && $this->isMisnamedTest($class, $method)) {
                    $records[strtolower($method->name)] ??= [$owner->getFileName() ?? $scope->getFile(), $alias->getStartLine(), $owner->getName(), $class->getName(), $method->name, $this->mayBeProvider($class, $method)];
                }
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
     * @param array<string, TraitMethod> $declared
     *
     * @return array<string, list<string>>
     */
    // the alias and the original name, each with the identity the class must still expose under it: an outer alias, the class's own method or the other trait insteadof selects is judged where it is declared
    private function namesRunUnder(Alias $alias, ClassReflection $owner, array $declared): array
    {
        $method = $declared[strtolower((string) $alias->newName)] ?? null;

        if (!$method instanceof TraitMethod || $owner->getName().':'.$alias->getStartLine() !== $method->renamedAt || null === $method->before) {
            return [];
        }

        return [$method->name => $method->identity(), $alias->method->toString() => $method->before];
    }

    private function isMisnamedTest(ClassReflection $class, TraitMethod $method): bool
    {
        $native = $class->getNativeReflection();

        return $method->isPublic && $native->hasMethod($method->name) && PhpUnitTestMethod::isMarkedOrNamedAsTest($native->getMethod($method->name)) && !TestMethodName::readsAsSentence($method->name);
    }

    private function mayBeProvider(ClassReflection $class, TraitMethod $method): bool
    {
        $native = $class->getNativeReflection()->getMethod($method->name);

        // because PHPUnit calls only a static provider and iterates what it returns
        return $native->isStatic() && !PhpUnitTestMethod::isMarked($native) && !$class->getNativeMethod($method->name)->getOnlyVariant()->getNativeReturnType()->isIterable()->no();
    }
}
