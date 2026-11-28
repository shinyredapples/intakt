<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;

/**
 * @phpstan-import-type Place from TraitAliasCollector
 * @phpstan-import-type MethodFact from TraitAliasCollector
 */
final readonly class ComposedTraitMethod
{
    // namedAt is the alias that gave the method its name and stays null under the name its trait declares, because only an alias can be fixed where it is written; aliasedAt lists the aliases of the method on its way to the class, innermost first, because a wrapper's alias outside tests/ cannot be fixed while the class's own alias can
    /**
     * @param MethodFact  $fact
     * @param Place       $source
     * @param Place|null  $namedAt
     * @param list<Place> $aliasedAt
     */
    public function __construct(
        public array $fact,
        public array $source,
        public ?array $namedAt = null,
        public array $aliasedAt = [],
    ) {
    }

    public function name(): string
    {
        return $this->fact['name'];
    }

    public function isRunAsTest(): bool
    {
        return Modifiers::PUBLIC === $this->fact['visibility'] && !$this->isAbstract() && ($this->fact['marked'] || PhpUnitTestMethod::isNamed($this->fact['name']));
    }

    public function isAbstract(): bool
    {
        return $this->fact['abstract'];
    }

    // because an alias without a modifier keeps the visibility the trait gives the method, whatever the same block does to the original name
    /**
     * @param Place $alias
     */
    public function underAlias(string $name, int $visibility, array $alias): self
    {
        return clone($this, ['fact' => ['name' => $name, 'visibility' => 0 === $visibility ? $this->fact['visibility'] : $visibility] + $this->fact, 'namedAt' => $alias]);
    }

    public function withVisibility(int $visibility): self
    {
        return clone($this, ['fact' => ['visibility' => $visibility] + $this->fact]);
    }

    /**
     * @param Place $alias
     */
    public function aliasedAt(array $alias): self
    {
        return clone($this, ['aliasedAt' => [...$this->aliasedAt, $alias]]);
    }
}
