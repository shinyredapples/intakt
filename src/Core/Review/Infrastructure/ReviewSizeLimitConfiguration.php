<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewSizeLimit;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

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

    // The limit must be filed under the key the projects tree gives the entry, or it is lost and read as the default: its name attribute whenever that is set (a float exported as text, a boolean as 0 or 1), else the outer key.
    public static function keyOf(int|string $outerKey, mixed $nameAttribute): int|string
    {
        return match (true) {
            is_float($nameAttribute) => var_export($nameAttribute, true),
            is_bool($nameAttribute) => (int) $nameAttribute,
            is_int($nameAttribute), is_string($nameAttribute) => $nameAttribute,
            default => $outerKey,
        };
    }

    // Each limit is validated alone under a fixed key, so no project name can change how the tree reads the others.
    /**
     * @param list<int|string>         $projectKeys
     * @param array<int|string, mixed> $written
     *
     * @return array<int|string, int>
     */
    public static function limitsOf(array $projectKeys, array $written): array
    {
        $limits = [];
        foreach ($projectKeys as $key) {
            $section = array_key_exists($key, $written) ? [self::KEY => $written[$key]] : [];
            /** @var array{projects: array<string, array{review_size_limit: int}>} $processed */
            $processed = new Processor()->processConfiguration(new self(), [['projects' => ['project' => $section]]]);
            $limits[$key] = $processed['projects']['project'][self::KEY];
        }

        return $limits;
    }
}
