<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

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
        $projects = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->arrayPrototype()->children();
        $tiers = $projects->arrayNode('tiers')->addDefaultsIfNotSet()->children();

        foreach (Tier::cases() as $tier) {
            $mapping = $tiers->arrayNode($tier->value)->children();
            $mapping->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
            $mapping->scalarNode('model')->isRequired()->cannotBeEmpty();
        }

        return $tree;
    }
}
