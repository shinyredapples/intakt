<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree refuses keys it does not know; the floors are the owner's for the one installation.
final class DiskConfiguration implements ConfigurationInterface
{
    // Room for a few build clones with their installed dependencies, so a step that starts above the floor can finish.
    public const int DEFAULT_MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;

    // A clone with its dependencies holds tens of thousands of files, so the default keeps room for a few of them.
    public const int DEFAULT_MIN_FREE_INODES = 100_000;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('disk')->addDefaultsIfNotSet()->children()
            ->integerNode('min_free_bytes')->defaultValue(self::DEFAULT_MIN_FREE_BYTES)->min(1)->end()
            ->integerNode('min_free_inodes')->defaultValue(self::DEFAULT_MIN_FREE_INODES)->min(1)->end();

        return $tree;
    }
}
