<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewSizeLimit;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know.
final class ReviewSizeLimitConfiguration implements ConfigurationInterface
{
    public const string KEY = 'review_size_limit';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children()
            ->variableNode(self::KEY)->defaultValue(ReviewSizeLimit::DEFAULT_CHARACTERS)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => !is_int($value) || $value < 1)
                ->thenInvalid('The review size limit of this project (review_size_limit) must be a positive whole number of characters of diff, for example 200000.');

        return $tree;
    }

    /**
     * @param array<string, array{review_size_limit: int}> $projects
     *
     * @return array<string, int>
     */
    public static function limitsOf(array $projects): array
    {
        return array_map(static fn (array $project): int => $project[self::KEY], $projects);
    }
}
