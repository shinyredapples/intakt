<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;

// merges every use statement of a class or trait because PHP binds all of them as one block, so an adaptation in one statement applies to a trait another statement names
final readonly class UseBlocks
{
    /**
     * @param list<Name>               $traits
     * @param list<TraitUseAdaptation> $adaptations
     */
    public function __construct(private string $owner, public array $traits, private array $adaptations)
    {
    }

    public static function of(ClassLike $declaration, string $owner): self
    {
        $uses = array_values(array_filter($declaration->stmts, static fn (Node $statement): bool => $statement instanceof TraitUse));

        return new self($owner, array_values(array_merge(...array_map(static fn (TraitUse $use): array => $use->traits, $uses))), array_values(array_merge(...array_map(static fn (TraitUse $use): array => $use->adaptations, $uses))));
    }

    // as PHP binds a trait: its aliases first, then, unless insteadof excludes it, the method under its own name with the last visibility change; the first concrete method to bind a name keeps it
    /**
     * @param array<string, TraitMethod> $methods
     * @param array<string, TraitMethod> $provided
     *
     * @return array<string, TraitMethod>
     */
    public function bind(array $methods, Name $trait, array $provided): array
    {
        foreach ($provided as $name => $method) {
            $methods = $this->bindAliases($methods, $trait, $method);

            if (!$this->excludes($trait, $method)) {
                $methods = $this->claim($methods, $name, $method->withVisibility($this->lastVisibilityChange($trait, $method)));
            }
        }

        return $methods;
    }

    /**
     * @param array<string, TraitMethod> $methods
     *
     * @return array<string, TraitMethod>
     */
    private function bindAliases(array $methods, Name $trait, TraitMethod $method): array
    {
        foreach ($this->adaptationsOf($trait, $method) as $alias) {
            if ($alias->newName instanceof Identifier) {
                $methods = $this->claim($methods, $alias->newName->toLowerString(), $method->renamed($alias->newName->toString(), $this->visibility($alias), $this->owner.':'.$alias->getStartLine()));
            }
        }

        return $methods;
    }

    /**
     * @return list<Alias>
     */
    private function adaptationsOf(Name $trait, TraitMethod $method): array
    {
        return array_values(array_filter($this->adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Alias && 0 === strcasecmp($adaptation->method->toString(), $method->name) && (!$adaptation->trait instanceof Name || 0 === strcasecmp($adaptation->trait->toString(), $trait->toString()))));
    }

    private function visibility(Alias $alias): int
    {
        return (int) $alias->newModifier & Modifiers::VISIBILITY_MASK;
    }

    private function excludes(Name $trait, TraitMethod $method): bool
    {
        return array_any($this->adaptations, static fn (Node $adaptation): bool => $adaptation instanceof Precedence && 0 === strcasecmp($adaptation->method->toString(), $method->name) && array_any($adaptation->insteadof, static fn (Name $excluded): bool => 0 === strcasecmp($excluded->toString(), $trait->toString())));
    }

    private function lastVisibilityChange(Name $trait, TraitMethod $method): int
    {
        $changes = array_filter(array_map($this->visibility(...), array_filter($this->adaptationsOf($trait, $method), static fn (Alias $alias): bool => !$alias->newName instanceof Identifier)), static fn (int $visibility): bool => 0 !== $visibility);

        return array_last($changes) ?? 0;
    }

    /**
     * @param array<string, TraitMethod> $methods
     *
     * @return array<string, TraitMethod>
     */
    private function claim(array $methods, string $name, TraitMethod $method): array
    {
        $bound = $methods[$name] ?? null;

        // because a concrete method another trait brings in implements an abstract one under the same name, whichever trait the block lists first
        if (!$bound instanceof TraitMethod || ($bound->isAbstract() && !$method->isAbstract())) {
            $methods[$name] = $method;
        }

        return $methods;
    }
}
