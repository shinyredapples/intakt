<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Infrastructure;

use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class ProjectModeConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()
            ->enumNode('mode')->isRequired()->values(array_column(ProjectMode::cases(), 'value'));

        return $tree;
    }
}
