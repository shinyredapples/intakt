<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Identifier;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

// because BetterReflection mixes the visibility of a trait method and its aliases and loses what a used trait's own use block changed (upstream/phpstan-trait-alias-hidden-original-visibility/), the use blocks are composed from the source as PHP composes them
final readonly class TraitComposition
{
    public function __construct(private ReflectionProvider $reflections)
    {
    }

    /**
     * @return array<string, TraitMethod> keyed by lowercase name, without the methods the class or trait declares itself
     */
    public function takenFromTraits(ClassReflection $class): array
    {
        // because most classes use no trait, and parsing their source would tell nothing
        $declaration = [] === $class->getNativeReflection()->getTraitNames() ? null : $this->declarationOf($class);

        if (!$declaration instanceof ClassLike) {
            return [];
        }

        $uses = array_values(array_filter($declaration->stmts, static fn (Node $statement): bool => $statement instanceof TraitUse));
        $adaptations = array_merge(...array_map(static fn (TraitUse $use): array => $use->adaptations, $uses));
        $methods = [];

        foreach (array_merge(...array_map(static fn (TraitUse $use): array => $use->traits, $uses)) as $trait) {
            if ($this->reflections->hasClass($trait->toString())) {
                $methods = $this->bind($methods, $trait, $this->methodsOf($this->reflections->getClass($trait->toString())), $adaptations, $class->getName());
            }
        }

        return array_diff_key($methods, array_change_key_case(array_flip(array_map(static fn (ClassMethod $method): string => $method->name->toString(), $declaration->getMethods()))));
    }

    public function isPublic(ClassReflection $class, ReflectionMethod $method): bool
    {
        if (!$method->getBetterReflection()->getDeclaringClass()->isTrait()) {
            return $method->isPublic();
        }

        for ($owner = $class; $owner instanceof ClassReflection; $owner = $owner->getParentClass()) {
            $taken = $this->takenFromTraits($owner)[strtolower($method->getName())] ?? null;

            if ($taken instanceof TraitMethod) {
                return $taken->isPublic;
            }
        }

        return $method->isPublic();
    }

    /**
     * @param array<string, TraitMethod> $methods
     * @param array<string, TraitMethod> $provided
     * @param list<Node>                 $adaptations
     *
     * @return array<string, TraitMethod>
     */
    // as PHP binds a trait: its aliases first, then, unless insteadof excludes it, the method under its own name with the last visibility change; the first trait to bind a name keeps it
    private function bind(array $methods, Name $trait, array $provided, array $adaptations, string $owner): array
    {
        foreach ($provided as $name => $method) {
            foreach ($this->adaptationsOf($adaptations, $trait, $method) as $alias) {
                if (null !== $alias->newName) {
                    $methods[$alias->newName->toLowerString()] ??= $method->renamed($alias->newName->toString(), $this->visibility($alias), $owner.':'.$alias->getStartLine());
                }
            }

            if (!$this->isExcluded($adaptations, $trait, $method)) {
                $changes = array_filter($this->adaptationsOf($adaptations, $trait, $method), fn (Alias $alias): bool => !$alias->newName instanceof Identifier && null !== $this->visibility($alias));
                $methods[$name] ??= $method->withVisibility(array_last($changes) instanceof Alias ? $this->visibility(array_last($changes)) : null);
            }
        }

        return $methods;
    }

    /**
     * @return array<string, TraitMethod>
     */
    private function methodsOf(ClassReflection $trait): array
    {
        $own = array_map(static fn (ClassMethod $method): TraitMethod => TraitMethod::declaredIn($trait->getName(), $method->name->toString(), $method->isPublic()), $this->declarationOf($trait)?->getMethods() ?? []);

        return array_column(array_map(static fn (TraitMethod $method): array => [strtolower($method->name), $method], $own), 1, 0) + $this->takenFromTraits($trait);
    }

    /**
     * @param list<Node> $adaptations
     *
     * @return list<Alias>
     */
    private function adaptationsOf(array $adaptations, Name $trait, TraitMethod $method): array
    {
        return array_values(array_filter($adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Alias && 0 === strcasecmp($adaptation->method->toString(), $method->name) && (!$adaptation->trait instanceof Name || 0 === strcasecmp($adaptation->trait->toString(), $trait->toString()))));
    }

    /**
     * @param list<Node> $adaptations
     */
    private function isExcluded(array $adaptations, Name $trait, TraitMethod $method): bool
    {
        return array_any($adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Precedence && 0 === strcasecmp($adaptation->method->toString(), $method->name) && array_any($adaptation->insteadof, static fn (Name $excluded): bool => 0 === strcasecmp($excluded->toString(), $trait->toString())));
    }

    private function visibility(Alias $alias): ?bool
    {
        return 0 === ((int) $alias->newModifier & Modifiers::VISIBILITY_MASK) ? null : 0 !== ((int) $alias->newModifier & Modifiers::PUBLIC);
    }

    private function declarationOf(ClassReflection $class): ?ClassLike
    {
        $nodes = new NodeTraverser(new NameResolver())->traverse(new ParserFactory()->createForHostVersion()->parse($class->getNativeReflection()->getBetterReflection()->getLocatedSource()->getSource()) ?? []);
        $declaration = new NodeFinder()->findFirst($nodes, static fn (Node $node): bool => $node instanceof ClassLike && 0 === strcasecmp((string) $node->namespacedName, $class->getName()));

        return $declaration instanceof ClassLike ? $declaration : null;
    }
}
