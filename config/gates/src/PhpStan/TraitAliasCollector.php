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

/**
 * @phpstan-type AliasRecord array{string, int, string, string, string, bool, string, string, array{string, int, string}|null}
 *
 * @implements Collector<TraitUse, non-empty-list<AliasRecord>>
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
     * @return non-empty-list<AliasRecord>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || !PhpUnitTestMethod::isTestCase($class)) {
            return null;
        }

        $use = new TraitUseScope($node, $class, $scope->getTraitReflection() ?? $class);
        $records = [];

        foreach ($node->adaptations as $alias) {
            if ($alias instanceof Alias && $alias->newName instanceof Identifier) {
                // the first alias wins, because one original name aliased twice is one name to fix
                $records += $this->misnamedNamesOf($alias, $use);
            }
        }

        return [] === $records ? null : array_values($records);
    }

    /**
     * @return array<string, AliasRecord>
     */
    private function misnamedNamesOf(Alias $alias, TraitUseScope $use): array
    {
        $declared = $this->declaredMethod($alias, $use->declarer);

        if (!$declared instanceof ReflectionMethod) {
            return [];
        }

        $records = [];
        $source = [(string) $declared->getFileName(), $declared->getBetterReflection()->getDeclaringClass()->getName()];
        $exposed = $this->boundAs((string) $alias->newName?->toString(), $use->class, $declared);

        if ($exposed instanceof ReflectionMethod && $this->aliasIsPublic($alias, $declared) && $use->keepsPublic($exposed) && $this->isMisnamed($exposed)) {
            $records['alias '.$alias->getStartLine().' '.strtolower($exposed->getName())] = [...$this->location($alias, $use->declarer), ...$this->judged($use->class, $exposed), ...$source, $this->exposer($use->class)];
        }

        $original = $this->boundAs($alias->method->toString(), $use->class, $declared);

        if ($original instanceof ReflectionMethod && $this->originalIsPublic($declared, $use) && $use->keepsPublic($original) && $this->isMisnamed($original)) {
            $records['original '.strtolower($original->getName())] = [...$this->location($alias, $use->declarer), ...$this->judged($use->class, $original), ...$source, null];
        }

        return $records;
    }

    // because PHP resolves an adaptation without a trait name against every trait the declaring class or trait uses
    private function declaredMethod(Alias $alias, ClassReflection $declarer): ?ReflectionMethod
    {
        $method = $alias->method->toString();
        $named = $alias->trait instanceof Name ? $alias->trait->toString() : null;
        $trait = array_find($declarer->getTraits(), static fn (ClassReflection $trait): bool => (null === $named || 0 === strcasecmp($named, $trait->getName())) && $trait->getNativeReflection()->hasMethod($method));

        return $trait?->getNativeReflection()->getMethod($method);
    }

    // because the class does not run this trait method under a name it declares itself or takes from another trait or wrapper with insteadof
    private function boundAs(string $name, ClassReflection $class, ReflectionMethod $declared): ?ReflectionMethod
    {
        $native = $class->getNativeReflection();

        return $native->hasMethod($name) && TraitMethodDeclaration::isSame($native->getMethod($name), $declared) ? $native->getMethod($name) : null;
    }

    // because BetterReflection gives an alias the visibility of any adaptation of its method (upstream/phpstan-trait-alias-drops-visibility/), the use block that declares the alias decides
    private function aliasIsPublic(Alias $alias, ReflectionMethod $declared): bool
    {
        $modifier = (int) $alias->newModifier;

        return 0 !== ($modifier & Modifiers::VISIBILITY_MASK) ? 0 !== ($modifier & Modifiers::PUBLIC) : $declared->isPublic();
    }

    private function isMisnamed(ReflectionMethod $method): bool
    {
        return PhpUnitTestMethod::isNamedOrMarked($method) && !$this->names->isSentence($method->getName());
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

    /**
     * @return array{string, int, string}
     */
    private function exposer(ClassReflection $class): array
    {
        return [(string) $class->getFileName(), $class->getNativeReflection()->getStartLine(), $class->getName()];
    }

    // because BetterReflection drops `method as private;` for a method the same use block also aliases (upstream/phpstan-trait-alias-drops-visibility/), the last such adaptation in the block decides
    private function originalIsPublic(ReflectionMethod $declared, TraitUseScope $use): bool
    {
        $isPublic = $declared->isPublic();

        foreach ($use->node->adaptations as $adaptation) {
            if ($adaptation instanceof Alias && !$adaptation->newName instanceof Identifier && 0 !== ((int) $adaptation->newModifier & Modifiers::VISIBILITY_MASK) && $this->changes($adaptation, $declared, $use)) {
                $isPublic = 0 !== ((int) $adaptation->newModifier & Modifiers::PUBLIC);
            }
        }

        return $isPublic;
    }

    private function changes(Alias $adaptation, ReflectionMethod $declared, TraitUseScope $use): bool
    {
        $method = $this->declaredMethod($adaptation, $use->declarer);

        return $method instanceof ReflectionMethod && TraitMethodDeclaration::isSame($method, $declared);
    }
}
