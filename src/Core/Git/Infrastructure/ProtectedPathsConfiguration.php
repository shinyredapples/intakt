<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Infrastructure;

use Intakt\Core\Git\Domain\ProtectedPaths;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class ProtectedPathsConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()
            ->arrayNode('protected_paths')->stringPrototype()->end()
            // Checked here with the rule the check itself applies, so a malformed entry fails at boot instead of protecting nothing; the string prototype has refused any other value before.
            ->validate()->always(static function (array $entries): array {
                new ProtectedPaths(array_values(array_filter($entries, is_string(...))));

                return $entries;
            })->end();

        return $tree;
    }
}
