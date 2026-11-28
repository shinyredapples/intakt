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
    // namedAt is the alias that gave the method its name and stays null under the name its trait declares, because only an alias can be fixed where it is written; aliasedAt is the first alias of the method on its way to the class
    /**
     * @param MethodFact $fact
     * @param Place      $source
     * @param Place|null $namedAt
     * @param Place|null $aliasedAt
     */
    public function __construct(
        public array $fact,
        public array $source,
        public ?array $namedAt = null,
        public ?array $aliasedAt = null,
    ) {
    }

    public function name(): string
    {
        return $this->fact['name'];
    }

    public function isRunAsTest(): bool
    {
        return Modifiers::PUBLIC === $this->fact['visibility'] && !$this->fact['abstract'] && ($this->fact['marked'] || PhpUnitTestMethod::isNamed($this->fact['name']));
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

    // because one original name aliased several times is one name to fix, the first alias keeps the place
    /**
     * @param Place $alias
     */
    public function aliasedAt(array $alias): self
    {
        return clone($this, ['aliasedAt' => $this->aliasedAt ?? $alias]);
    }
}
