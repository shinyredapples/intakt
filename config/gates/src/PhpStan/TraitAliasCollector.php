<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;

/**
 * @phpstan-type Place array{string, int, string}
 * @phpstan-type MethodFact array{name: string, line: int, visibility: int, abstract: bool, marked: bool, provider: bool}
 * @phpstan-type TakenMethod array{fact: MethodFact, source: Place}
 * @phpstan-type UsedTrait array{name: string, file: string, methods: list<MethodFact>, taken: list<TakenMethod>}
 * @phpstan-type AliasRecord array{trait: string|null, method: string, name: string|null, visibility: int, line: int}
 * @phpstan-type Exclusion array{trait: string, method: string}
 * @phpstan-type Adaptations array{aliases: list<AliasRecord>, exclusions: list<Exclusion>}
 * @phpstan-type UseBlock array{class: Place, declarer: array{string, string}, statement: string, own: list<string>, traits: list<UsedTrait>, aliases: list<AliasRecord>, exclusions: list<Exclusion>}
 *
 * @implements Collector<TraitUse, UseBlock>
 */
final readonly class TraitAliasCollector implements Collector
{
    private SentenceTestName $names;

    public function __construct(private Parser $parser)
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
            // with the file position, because two `use` statements on one line are two statements PHP applies one after the other
            'statement' => $node->getStartFilePos().':'.$node->getStartLine().':'.implode(',', array_map(static fn (Name $trait): string => $trait->toString(), $node->traits)),
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
        $own = $this->ownMethods($trait);
        $taken = array_values(array_udiff($trait->getNativeReflection()->getMethods(), $own, static fn (ReflectionMethod $left, ReflectionMethod $right): int => strcasecmp($left->getName(), $right->getName())));
        $adaptations = [] === $taken ? ['aliases' => [], 'exclusions' => []] : $this->adaptationsIn($trait);

        return [
            'name' => $trait->getName(),
            'file' => (string) $trait->getFileName(),
            'methods' => array_map(fn (ReflectionMethod $method): array => $this->fact($trait, $method), $own),
            'taken' => array_map(fn (ReflectionMethod $method): array => $this->takenFact($trait, $method, $adaptations), $taken),
        ];
    }

    // because a wrapper trait whose file a changed-files run did not analyze has no collected block, its own `use` blocks are read from its file as written
    /**
     * @return Adaptations
     */
    private function adaptationsIn(ClassReflection $trait): array
    {
        $file = $trait->getFileName();
        $line = $trait->getNativeReflection()->getStartLine();
        $declaration = null === $file ? null : new NodeFinder()->findFirst($this->parser->parseFile($file), static fn (Node $node): bool => $node instanceof Trait_ && $node->getStartLine() === $line);
        $uses = $declaration instanceof Trait_ ? $declaration->getTraitUses() : [];

        return [
            'aliases' => array_merge([], ...array_map($this->aliases(...), $uses)),
            'exclusions' => array_merge([], ...array_map($this->exclusions(...), $uses)),
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

    // because a changed-files run may leave out a wrapper trait's own `use` block, the methods the wrapper takes from its traits are kept as the wrapper exposes them, so the composition still reaches them
    /**
     * @param Adaptations $adaptations
     *
     * @return TakenMethod
     */
    private function takenFact(ClassReflection $trait, ReflectionMethod $method, array $adaptations): array
    {
        $declarer = $method->getBetterReflection()->getDeclaringClass();

        return ['fact' => ['visibility' => $this->exposedVisibility($trait, $method, $adaptations)] + $this->fact($trait, $method), 'source' => [(string) $declarer->getFileName(), (int) $method->getStartLine(), $declarer->getName()]];
    }

    // because BetterReflection gives a method that one block both aliases and hides the wrong visibility (upstream/phpstan-trait-alias-drops-visibility/): an alias takes its own modifier or the trait method's visibility, and an original name the last visibility-only adaptation of it
    /**
     * @param Adaptations $adaptations
     */
    private function exposedVisibility(ClassReflection $wrapper, ReflectionMethod $method, array $adaptations): int
    {
        $name = $method->getName();
        $alias = array_find($adaptations['aliases'], static fn (array $adaptation): bool => null !== $adaptation['name'] && 0 === strcasecmp($adaptation['name'], $name));
        $hiding = array_find(array_reverse($adaptations['aliases']), static fn (array $adaptation): bool => null === $adaptation['name'] && 0 !== $adaptation['visibility'] && 0 === strcasecmp($adaptation['method'], $name));
        $written = null === $alias ? $hiding['visibility'] ?? 0 : $alias['visibility'];

        return 0 !== $written ? $written : $this->visibilityInTraitsOf($wrapper, $alias['trait'] ?? null, $alias['method'] ?? $name, $method, $adaptations['exclusions']);
    }

    // the trait that supplies the method is the one the alias names, or else the first that brings the name and that no insteadof excludes, as PHP picks it
    /**
     * @param list<Exclusion> $exclusions
     */
    private function visibilityInTraitsOf(ClassReflection $wrapper, ?string $named, string $original, ReflectionMethod $reflected, array $exclusions): int
    {
        $source = array_find($wrapper->getTraits(), static fn (ClassReflection $trait): bool => $trait->getNativeReflection()->hasMethod($original)
            && (null === $named || 0 === strcasecmp(ltrim($named, '\\'), $trait->getName()))
            && !array_any($exclusions, static fn (array $exclusion): bool => 0 === strcasecmp(ltrim($exclusion['trait'], '\\'), $trait->getName()) && 0 === strcasecmp($exclusion['method'], $original)));
        $public = $source?->getNativeReflection()->getMethod($original)->isPublic() ?? $reflected->isPublic();

        return $public ? Modifiers::PUBLIC : Modifiers::PRIVATE;
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
                $exclusions = [...$exclusions, ...array_map(static fn (Name $excluded): array => ['trait' => $excluded->toString(), 'method' => $adaptation->method->toString()], array_values($adaptation->insteadof))];
            }
        }

        return $exclusions;
    }
}
