<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Infrastructure;

use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final class WatchConfiguration implements ConfigurationInterface
{
    private const string GITHUB_REPOSITORY = '#^[A-Za-z0-9-]+/(?!\.+$)[A-Za-z0-9_.-]+$#';
    private const array KEYS = ['repository', 'base', 'task'];
    private const string BLANK = '/^\s*$/u';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $watch = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false)
            ->arrayPrototype()->children();

        // A string node and a blank check, because a number or a blank would boot and only fail, or reach every round, at the tick.
        foreach (self::KEYS as $key) {
            $watch->stringNode($key)->isRequired()->cannotBeEmpty()
                ->validate()->ifTrue(static fn (string $value): bool => 1 === preg_match(self::BLANK, $value))->thenInvalid(sprintf('The "%s" key must not be blank.', $key))->end();
        }

        // Optional, because a project watched through a local forge has no GitHub repository; the name is checked here so a typo fails at boot, not at the tick.
        $watch->stringNode('github')
            ->validate()->ifTrue(static fn (string $value): bool => 1 !== preg_match(self::GITHUB_REPOSITORY, $value))->thenInvalid('The "github" key must be owner/name.')->end();

        // Defaults to Intakt's own label, because a project beside the earlier loop must not build what that loop's label marks; a blank one would match no label and quietly stop the project's intake.
        $watch->stringNode('eligibility_label')->defaultValue(WorkLabels::ELIGIBILITY)->cannotBeEmpty()
            ->validate()->ifTrue(static fn (string $value): bool => 1 === preg_match(self::BLANK, $value))->thenInvalid('The "eligibility_label" key must not be blank.')->end();

        return $tree;
    }
}
