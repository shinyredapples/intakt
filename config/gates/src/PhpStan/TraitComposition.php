<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

// the methods one class takes from its traits are composed the way PHP composes them, each wrapper trait first and then the class, because a name must be followed along its route: the declaration it ends at cannot tell which wrapper supplied it
/**
 * @phpstan-import-type UseBlock from TraitAliasCollector
 * @phpstan-import-type UsedTrait from TraitAliasCollector
 * @phpstan-import-type AliasRecord from TraitAliasCollector
 * @phpstan-import-type Exclusion from TraitAliasCollector
 *
 * @phpstan-type Uses array{declarer: array{string, string}, own: list<string>, traits: list<UsedTrait>, aliases: array<string, list<AliasRecord>>, exclusions: array<string, list<Exclusion>>}
 */
final readonly class TraitComposition
{
    /**
     * @var array<string, Uses>
     */
    private array $uses;

    // keyed by statement because PHPStan visits the block of a wrapper once for every route that leads to it, while PHP applies each statement once
    /**
     * @param list<UseBlock> $blocks
     */
    public function __construct(array $blocks)
    {
        $uses = [];

        foreach ($blocks as $block) {
            $declarer = strtolower($block['declarer'][1]);
            $uses[$declarer] ??= ['declarer' => $block['declarer'], 'own' => $block['own'], 'traits' => $block['traits'], 'aliases' => [], 'exclusions' => []];
            $uses[$declarer]['aliases'][$block['statement']] = $block['aliases'];
            $uses[$declarer]['exclusions'][$block['statement']] = $block['exclusions'];
        }

        $this->uses = $uses;
    }

    /**
     * @return array<string, ComposedTraitMethod>
     */
    public function methodsOf(string $declarer): array
    {
        return $this->takenBy($declarer, []);
    }

    // the path is kept because traits that use each other in a cycle, a fatal error only at run time, must not send the gate into an endless recursion
    /**
     * @param list<string> $path
     *
     * @return array<string, ComposedTraitMethod>
     */
    private function takenBy(string $declarer, array $path): array
    {
        $key = strtolower($declarer);
        $uses = $this->uses[$key] ?? null;

        if (null === $uses || in_array($key, $path, true)) {
            return [];
        }

        $taken = [];

        foreach ($uses['traits'] as $trait) {
            $methods = array_values($this->methodsIn($trait, [...$path, $key]));
            $copies = array_merge([], ...array_map(fn (ComposedTraitMethod $method): array => $this->copiesOf($method, $trait['name'], $uses), $methods));
            $taken = array_reduce($copies, fn (array $bound, ComposedTraitMethod $copy): array => $this->bound($bound, $copy, $uses['own']), $taken);
        }

        return $taken;
    }

    // because a method the trait declares itself wins over one it takes from another trait
    /**
     * @param UsedTrait    $trait
     * @param list<string> $path
     *
     * @return array<string, ComposedTraitMethod>
     */
    private function methodsIn(array $trait, array $path): array
    {
        $declared = [];

        foreach ($trait['methods'] as $fact) {
            $declared[strtolower($fact['name'])] = new ComposedTraitMethod($fact, [$trait['file'], $fact['line'], $trait['name']]);
        }

        return $declared + $this->takenBy($trait['name'], $path);
    }

    // because PHP copies a method under each alias even when insteadof excludes its own name, and only a method that is not excluded keeps that name, with the last visibility the block gives it
    /**
     * @param Uses $uses
     *
     * @return list<ComposedTraitMethod>
     */
    private function copiesOf(ComposedTraitMethod $method, string $trait, array $uses): array
    {
        $copies = [];
        $kept = $method;

        foreach ($this->adaptationsOf($method, $trait, $uses) as $alias) {
            $place = [$uses['declarer'][0], $alias['line'], $uses['declarer'][1]];

            if (null !== $alias['name']) {
                $copies[] = $method->underAlias($alias['name'], $alias['visibility'], $place);
                $kept = $kept->aliasedAt($place);
            } elseif (0 !== $alias['visibility']) {
                $kept = $kept->withVisibility($alias['visibility']);
            }
        }

        return $this->isExcluded($method, $trait, $uses) ? $copies : [...$copies, $kept];
    }

    // because PHP resolves an adaptation without a trait name against every trait the class or trait uses
    /**
     * @param Uses $uses
     *
     * @return list<AliasRecord>
     */
    private function adaptationsOf(ComposedTraitMethod $method, string $trait, array $uses): array
    {
        return array_values(array_filter(
            array_merge([], ...array_values($uses['aliases'])),
            static fn (array $alias): bool => (null === $alias['trait'] || 0 === strcasecmp($alias['trait'], $trait)) && 0 === strcasecmp($alias['method'], $method->name()),
        ));
    }

    /**
     * @param Uses $uses
     */
    private function isExcluded(ComposedTraitMethod $method, string $trait, array $uses): bool
    {
        return array_any(
            array_merge([], ...array_values($uses['exclusions'])),
            static fn (array $exclusion): bool => 0 === strcasecmp($exclusion['trait'], $trait) && 0 === strcasecmp($exclusion['method'], $method->name()),
        );
    }

    // because a method the class or trait declares itself wins over a trait's, and the first trait that brings a name keeps it
    /**
     * @param array<string, ComposedTraitMethod> $taken
     * @param list<string>                       $own
     *
     * @return array<string, ComposedTraitMethod>
     */
    private function bound(array $taken, ComposedTraitMethod $copy, array $own): array
    {
        $name = strtolower($copy->name());

        return in_array($name, $own, true) ? $taken : $taken + [$name => $copy];
    }
}
