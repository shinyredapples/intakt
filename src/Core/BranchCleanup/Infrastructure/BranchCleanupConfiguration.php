<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class BranchCleanupConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()
            // Off by default, because a branch without Intakt's prefix is someone else's (docs/design/05-architecture/08-merge.md#branch-cleanup).
            ->booleanNode('clean_other_branches')->defaultFalse()->end()
            ->arrayNode('keep_branches')->stringPrototype()->cannotBeEmpty()->end()->end();

        return $tree;
    }
}
