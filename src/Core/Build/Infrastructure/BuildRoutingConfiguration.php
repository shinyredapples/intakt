<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\Setback;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final readonly class BuildRoutingConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $build = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()->arrayNode('build')->addDefaultsIfNotSet()->children();
        $build->enumNode('tier')->values(array_column(Tier::cases(), 'value'))->defaultValue(Tier::Standard->value);
        $build->arrayNode('sensitive_paths')->defaultValue([])
            ->stringPrototype()->cannotBeEmpty()
                ->validate()->ifTrue(static fn (string $glob): bool => !mb_check_encoding($glob, 'UTF-8'))->thenInvalid('A path pattern must be valid UTF-8.')->end()
            ->end()
            ->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The sensitive paths must be a list, not a map.');
        $build->arrayNode('escalate_on')->defaultValue(array_column(Setback::cases(), 'value'))
            ->enumPrototype()->values(array_column(Setback::cases(), 'value'))->end()
            ->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The escalation setbacks must be a list, not a map.');

        return $tree;
    }
}
