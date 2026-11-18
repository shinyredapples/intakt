<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; the reserve is the owner's for the one installation, so it sits beside the projects.
final class GitHubConfiguration implements ConfigurationInterface
{
    public const int DEFAULT_RESERVE_PERCENT = 20;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('github')->addDefaultsIfNotSet()->children()
            ->integerNode('reserve_percent')->min(0)->max(100)->defaultValue(self::DEFAULT_RESERVE_PERCENT);

        return $tree;
    }
}
