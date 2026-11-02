<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\UsageWindow;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; the windows are named as UsageWindow names them.
final class CeilingsConfiguration implements ConfigurationInterface
{
    public const float DEFAULT_CEILING = 90.0;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $ceilings = $tree->getRootNode()->addDefaultsIfNotSet()->children()
            ->arrayNode('budget')->addDefaultsIfNotSet()->children()
            ->arrayNode('ceilings')->addDefaultsIfNotSet()->normalizeKeys(false)->children();

        foreach (UsageWindow::cases() as $window) {
            // A float node keeps a whole number as an int, and the guard compares floats.
            // NaN becomes a string before normalization, because Symfony Config skips every check for NaN itself
            // (upstream/config-nan-skips-validation) and the float type check then refuses it with the node's path.
            $ceilings->floatNode($window->value)->min(1)->max(100)->defaultValue(self::DEFAULT_CEILING)
                ->beforeNormalization()->ifTrue(static fn (mixed $ceiling): bool => is_float($ceiling) && is_nan($ceiling))->then(static fn (): string => 'NaN')->end()
                ->validate()->always(static fn (int|float $ceiling): float => (float) $ceiling);
        }

        return $tree;
    }
}
