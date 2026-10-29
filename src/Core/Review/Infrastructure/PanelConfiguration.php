<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class PanelConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $panel = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)->arrayPrototype()->children();
        $panel->enumNode('quorum')->isRequired()->values(array_column(QuorumPolicy::cases(), 'value'));
        $reviewer = $panel->arrayNode('reviewers')->isRequired()->requiresAtLeastOneElement()->arrayPrototype()->children();
        $reviewer->stringNode('role')->isRequired()->cannotBeEmpty();
        $reviewer->enumNode('tier')->isRequired()->values(array_column(Tier::cases(), 'value'));
        $reviewer->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
        $reviewer->stringNode('prompt')->isRequired()->cannotBeEmpty();
        $reviewer->integerNode('budget')->isRequired()->min(1);

        return $tree;
    }
}
