<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class ReviewJobConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()
            ->stringNode('image')->isRequired()->cannotBeEmpty();

        return $tree;
    }

    /**
     * @param array<string, array{image: string}> $projects
     *
     * @return array<string, string>
     */
    public static function imagesOf(array $projects): array
    {
        return array_map(static fn (array $project): string => $project['image'], $projects);
    }
}
