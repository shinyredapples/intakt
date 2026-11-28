<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;

/**
 * @phpstan-type Place array{string, int, string}
 * @phpstan-type MethodFact array{name: string, line: int, visibility: int, abstract: bool, marked: bool, provider: bool}
 * @phpstan-type UsedTrait array{name: string, file: string, methods: list<MethodFact>}
 * @phpstan-type AliasRecord array{trait: string|null, method: string, name: string|null, visibility: int, line: int}
 * @phpstan-type Exclusion array{trait: string, method: string}
 * @phpstan-type UseBlock array{class: Place, declarer: array{string, string}, statement: string, own: list<string>, traits: list<UsedTrait>, aliases: list<AliasRecord>, exclusions: list<Exclusion>}
 *
 * @implements Collector<TraitUse, UseBlock>
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

    // PHPStan visits a trait's own `use` once inside each class that uses the trait, so the blocks of a class and of every wrapper trait on the way to a method are recorded per class, and TraitComposition follows the route each name takes through them
    /**
     * @return UseBlock|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || !PhpUnitTestMethod::isTestCase($class)) {
            return null;
        }

        $declarer = $scope->getTraitReflection() ?? $class;

        return [
            'class' => [(string) $class->getFileName(), $class->getNativeReflection()->getStartLine(), $class->getName()],
            'declarer' => [(string) $declarer->getFileName(), $declarer->getName()],
            'statement' => $node->getStartLine().':'.implode(',', array_map(static fn (Name $trait): string => $trait->toString(), $node->traits)),
            'own' => array_map(static fn (ReflectionMethod $method): string => strtolower($method->getName()), $this->ownMethods($declarer)),
            'traits' => array_map($this->usedTrait(...), array_values($declarer->getTraits())),
            'aliases' => $this->aliases($node),
            'exclusions' => $this->exclusions($node),
        ];
    }

    // because a method a class or trait takes from a trait still names that trait as its declaring class, while one it declares itself names the class or trait
    /**
     * @return list<ReflectionMethod>
     */
    private function ownMethods(ClassReflection $owner): array
    {
        return array_values(array_filter(
            $owner->getNativeReflection()->getMethods(),
            static fn (ReflectionMethod $method): bool => 0 === strcasecmp($method->getBetterReflection()->getDeclaringClass()->getName(), $owner->getName()),
        ));
    }

    /**
     * @return UsedTrait
     */
    private function usedTrait(ClassReflection $trait): array
    {
        return [
            'name' => $trait->getName(),
            'file' => (string) $trait->getFileName(),
            'methods' => array_map(fn (ReflectionMethod $method): array => $this->fact($trait, $method), $this->ownMethods($trait)),
        ];
    }

    /**
     * @return MethodFact
     */
    private function fact(ClassReflection $trait, ReflectionMethod $method): array
    {
        return [
            'name' => $method->getName(),
            'line' => (int) $method->getStartLine(),
            'visibility' => $method->isPublic() ? Modifiers::PUBLIC : Modifiers::PRIVATE,
            'abstract' => $method->isAbstract(),
            'marked' => PhpUnitTestMethod::isMarked($method),
            'provider' => $this->names->mayBeProvider($trait, $method),
        ];
    }

    // because BetterReflection gives a method that one block both aliases and changes the visibility of the wrong visibility (upstream/phpstan-trait-alias-drops-visibility/), the adaptations are recorded as the block writes them
    /**
     * @return list<AliasRecord>
     */
    private function aliases(TraitUse $use): array
    {
        $aliases = [];

        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Alias) {
                $aliases[] = [
                    'trait' => $adaptation->trait?->toString(),
                    'method' => $adaptation->method->toString(),
                    'name' => $adaptation->newName?->toString(),
                    'visibility' => (int) $adaptation->newModifier & Modifiers::VISIBILITY_MASK,
                    'line' => $adaptation->getStartLine(),
                ];
            }
        }

        return $aliases;
    }

    /**
     * @return list<Exclusion>
     */
    private function exclusions(TraitUse $use): array
    {
        $exclusions = [];

        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Precedence) {
                $exclusions = [...$exclusions, ...array_map(static fn (Name $excluded): array => ['trait' => $excluded->toString(), 'method' => $adaptation->method->toString()], $adaptation->insteadof)];
            }
        }

        return $exclusions;
    }
}
