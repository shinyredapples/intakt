<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree refuses keys it does not know; retention is the owner's for the one installation.
final class RetentionConfiguration implements ConfigurationInterface
{
    public const int DEFAULT_DAYS = 30;

    public const string FOREVER = 'forever';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->addDefaultsIfNotSet()->children()->arrayNode('retention')->addDefaultsIfNotSet()->children()
            ->scalarNode('conversation_days')
                ->defaultValue(self::DEFAULT_DAYS)
                ->validate()
                    ->ifTrue(static fn (mixed $value): bool => self::FOREVER !== $value && (!is_int($value) || $value < 1))
                    ->thenInvalid('retention.conversation_days must be a whole number of days from 1, or "forever", got %s.');

        return $tree;
    }

    // Null stands for keeping forever.
    public static function daysOf(int|string $setting): ?int
    {
        return self::FOREVER === $setting ? null : (int) $setting;
    }
}
