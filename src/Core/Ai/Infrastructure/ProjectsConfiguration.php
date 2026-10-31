<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Validated when the container is built, so a bad key fails there with its path instead of surfacing mid-run.
final class ProjectsConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $this->addAccounts($tree);
        $projects = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)->arrayPrototype()->children();
        $tiers = $projects->arrayNode('tiers')->addDefaultsIfNotSet()->children();

        foreach (Tier::cases() as $tier) {
            $mapping = $tiers->arrayNode($tier->value)->children();
            $mapping->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
            $mapping->stringNode('model')->isRequired()->cannotBeEmpty()
                ->validate()->ifTrue(static fn (string $model): bool => !mb_check_encoding($model, 'UTF-8'))->thenInvalid('The "model" key must be valid UTF-8.')->end()
                ->validate()->ifTrue(static fn (string $model): bool => 1 === preg_match('/^\s*$/u', $model))->thenInvalid('The "model" key must not be blank.')->end();
        }

        return $tree;
    }

    // A list per provider, in the order the accounts are tried; a provider not listed has its default account only.
    private function addAccounts(TreeBuilder $tree): void
    {
        $accounts = $tree->getRootNode()->children()->arrayNode('accounts')->addDefaultsIfNotSet()->children();

        foreach (Provider::cases() as $provider) {
            $accounts->arrayNode($provider->value)->defaultValue([Account::DEFAULT])->requiresAtLeastOneElement()->stringPrototype()->end()
                ->validate()->ifTrue(static fn (array $names): bool => [] !== preg_grep(Account::NAME_PATTERN, $names, PREG_GREP_INVERT))->thenInvalid('An account name must start with a lowercase letter and hold only lowercase letters, digits and underscores.')->end()
                ->validate()->ifTrue(static fn (array $names): bool => count($names) !== count(array_unique($names)))->thenInvalid('An account must be listed once.')->end();
        }
    }
}
