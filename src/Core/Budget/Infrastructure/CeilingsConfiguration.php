<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\UsageWindow;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; the windows are named as UsageWindow names them.
final class CeilingsConfiguration implements ConfigurationInterface
{
    public const float DEFAULT_CEILING = 90.0;
    public const float DEFAULT_SHADOW_CEILING = 70.0;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $budget = $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('budget')->addDefaultsIfNotSet()->children();
        $this->windows($budget->arrayNode('ceilings'), self::DEFAULT_CEILING);
        $this->windows($budget->arrayNode('shadow_ceilings'), self::DEFAULT_SHADOW_CEILING);

        return $tree;
    }

    private function windows(ArrayNodeDefinition $node, float $default): void
    {
        $windows = $node->addDefaultsIfNotSet()->normalizeKeys(false)->children();

        foreach (UsageWindow::cases() as $window) {
            // NaN becomes a string because Symfony Config skips every check for NaN (upstream/config-nan-skips-validation); ints become floats for the guard.
            $windows->floatNode($window->value)->min(1)->max(100)->defaultValue($default)
                ->beforeNormalization()->ifTrue(static fn (mixed $ceiling): bool => is_float($ceiling) && is_nan($ceiling))->then(static fn (): string => 'NaN')->end()
                ->validate()->always(static fn (int|float $ceiling): float => (float) $ceiling);
        }
    }
}
