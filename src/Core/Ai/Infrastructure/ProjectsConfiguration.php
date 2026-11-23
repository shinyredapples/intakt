<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Validated when the container is built, so a bad key fails there with its path instead of surfacing mid-run.
final class ProjectsConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $this->addAccounts($tree);
        $this->addCodex($tree);
        $projects = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)->arrayPrototype()->children();
        $tiers = $projects->arrayNode('tiers')->addDefaultsIfNotSet()->children();

        foreach (Tier::cases() as $tier) {
            $mapping = $tiers->arrayNode($tier->value)->children();
            $this->addMapping($mapping);
            // One limit per tier, also for its substitutes, so a verdict has room whichever provider answers.
            $mapping->integerNode('max_output')->min(1)->defaultValue($tier->defaultMaxOutput());
            // In the order they are tried, and none by default, so a tier never leaves its provider unless the owner named another.
            $this->addMapping($mapping->arrayNode('substitutes')->defaultValue([])->arrayPrototype()->children());
        }

        $tree->getRootNode()->validate()->ifTrue($this->leavesThePinnedCodexModel(...))
            ->thenInvalid('A tier or substitute on provider "codex" must map the model pinned under codex.model, because every Codex call runs on that model.');

        return $tree;
    }

    // A list, not a map, because its order is the order the accounts are tried; a provider not listed keeps its default account so a single key needs no entry.
    private function addAccounts(TreeBuilder $tree): void
    {
        $accounts = $tree->getRootNode()->children()->arrayNode('accounts')->addDefaultsIfNotSet()->children();

        foreach (Provider::cases() as $provider) {
            $accounts->arrayNode($provider->value)->defaultValue([Account::DEFAULT])->requiresAtLeastOneElement()->stringPrototype()->end()
                ->validate()->ifTrue(static fn (array $names): bool => [] !== preg_grep(Account::NAME_PATTERN, $names, PREG_GREP_INVERT))->thenInvalid('An account name must start with a lowercase letter and hold only lowercase letters, digits and underscores.')->end()
                ->validate()->ifTrue(static fn (array $names): bool => count($names) !== count(array_unique($names, SORT_REGULAR)))->thenInvalid('An account must be listed once.')->end();
        }
    }

    // Optional, since only a Codex subscription account needs it; once present, both are required so neither falls back to the CLI's default.
    private function addCodex(TreeBuilder $tree): void
    {
        $codex = $tree->getRootNode()->children()->arrayNode(Provider::Codex->configKey())->children();
        $this->addModel($codex);
        $codex->enumNode('effort')->isRequired()->values(array_column(CodexEffort::cases(), 'value'));
    }

    private function addMapping(NodeBuilder $mapping): void
    {
        $mapping->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
        $this->addModel($mapping);
    }

    /**
     * @param array{projects?: array<string, array{tiers: array<string, array{provider: string, model: string, substitutes: list<array{provider: string, model: string}>}>}>, codex?: array{model: string}} $intakt
     */
    private function leavesThePinnedCodexModel(array $intakt): bool
    {
        $pinned = $intakt[Provider::Codex->configKey()]['model'] ?? null;

        return array_any(
            $this->mappingsOf($intakt['projects'] ?? []),
            static fn (array $mapping): bool => Provider::Codex->value === $mapping['provider'] && $pinned !== $mapping['model'],
        );
    }

    /**
     * @param array<string, array{tiers: array<string, array{provider: string, model: string, substitutes: list<array{provider: string, model: string}>}>}> $projects
     *
     * @return list<array{provider: string, model: string}>
     */
    private function mappingsOf(array $projects): array
    {
        $mappings = [];

        foreach ($projects as $project) {
            foreach ($project['tiers'] as $tier) {
                $mappings = [...$mappings, ['provider' => $tier['provider'], 'model' => $tier['model']], ...$tier['substitutes']];
            }
        }

        return $mappings;
    }

    private function addModel(NodeBuilder $parent): void
    {
        $parent->stringNode('model')->isRequired()->cannotBeEmpty()
            ->validate()->ifTrue(static fn (string $model): bool => !mb_check_encoding($model, 'UTF-8'))->thenInvalid('The "model" key must be valid UTF-8.')->end()
            ->validate()->ifTrue(static fn (string $model): bool => 1 === preg_match('/^\s*$/u', $model))->thenInvalid('The "model" key must not be blank.')->end();
    }
}
