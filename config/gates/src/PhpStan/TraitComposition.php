<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
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

    // keyed by lowercase name because PHP matches method names in any letter case, and without the methods the class or trait declares itself because those win over every trait
    /**
     * @return array<string, TraitMethod>
     */
    public function takenFromTraits(ClassReflection $class): array
    {
        // because most classes use no trait, and parsing their source would tell nothing
        $declaration = [] === $class->getNativeReflection()->getTraitNames() ? null : $this->declarationOf($class);

        if (!$declaration instanceof ClassLike) {
            return [];
        }

        $blocks = UseBlocks::of($declaration, $class->getName());
        $methods = [];

        foreach ($blocks->traits as $trait) {
            if ($this->reflections->hasClass($trait->toString())) {
                $methods = $blocks->bind($methods, $trait, $this->methodsOf($this->reflections->getClass($trait->toString())));
            }
        }

        return array_diff_key($methods, $this->ownNames($declaration));
    }

    public function isPublic(ClassReflection $class, ReflectionMethod $method): bool
    {
        if (!$method->getBetterReflection()->getDeclaringClass()->isTrait()) {
            return $method->isPublic();
        }

        for ($owner = $class; $owner instanceof ClassReflection; $owner = $owner->getParentClass()) {
            $taken = $this->takenFromTraits($owner)[strtolower($method->getName())] ?? null;

            if ($taken instanceof TraitMethod) {
                return $taken->isPublic();
            }
        }

        return $method->isPublic();
    }

    private function declarationOf(ClassReflection $class): ?ClassLike
    {
        $nodes = new NodeTraverser(new NameResolver())->traverse(new ParserFactory()->createForHostVersion()->parse($class->getNativeReflection()->getBetterReflection()->getLocatedSource()->getSource()) ?? []);
        $declaration = new NodeFinder()->findFirst($nodes, static fn (Node $node): bool => $node instanceof ClassLike && 0 === strcasecmp((string) $node->namespacedName, $class->getName()));

        return $declaration instanceof ClassLike ? $declaration : null;
    }

    /**
     * @return array<string, TraitMethod>
     */
    private function methodsOf(ClassReflection $trait): array
    {
        $own = array_map(static fn (ClassMethod $method): TraitMethod => TraitMethod::declaredIn($trait->getName(), $method), $this->declarationOf($trait)?->getMethods() ?? []);

        return array_column(array_map(static fn (TraitMethod $method): array => [strtolower($method->name), $method], $own), 1, 0) + $this->takenFromTraits($trait);
    }

    /**
     * @return array<string, int>
     */
    private function ownNames(ClassLike $declaration): array
    {
        return array_change_key_case(array_flip(array_map(static fn (ClassMethod $method): string => $method->name->toString(), $declaration->getMethods())));
    }
}
