<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class WatchConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $watch = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children();
        $watch->scalarNode('repository')->isRequired()->cannotBeEmpty();
        $watch->scalarNode('base')->isRequired()->cannotBeEmpty();
        $watch->scalarNode('task')->isRequired()->cannotBeEmpty();

        return $tree;
    }
}
