<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PanelConfigurationFailure;
use Intakt\Core\Review\Contract\QuorumPolicy;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final readonly class PanelConfiguration implements ConfigurationInterface
{
    /**
     * @param array<int|string, array<string, string>> $tierProviders
     */
    public function __construct(
        private array $tierProviders,
    ) {
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('intakt');
        $projects = $tree->getRootNode()->children()->arrayNode('projects')->useAttributeAsKey('name')->normalizeKeys(false);
        $projects->validate()->always($this->refuseUnusablePanels(...));
        $panel = $projects->arrayPrototype()->children();
        $panel->enumNode('quorum')->isRequired()->values(array_column(QuorumPolicy::cases(), 'value'));
        $this->reviewers($panel->arrayNode('reviewers'));
        $rules = $panel->arrayNode('rules')->defaultValue([]);
        $rules->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The rules must be a list, not a map.');
        $rule = $rules->arrayPrototype();
        $rule->validate()->ifTrue(static fn (array $rule): bool => !isset($rule['min_lines']) && [] === $rule['paths'])->thenInvalid('A rule needs "min_lines" or "paths" to trigger on.');
        $ruleKeys = $rule->children();
        $ruleKeys->integerNode('min_lines')->min(1);
        $ruleKeys->arrayNode('paths')->defaultValue([])
            ->stringPrototype()->cannotBeEmpty()
                ->validate()->ifTrue(static fn (string $glob): bool => !mb_check_encoding($glob, 'UTF-8'))->thenInvalid('A path pattern must be valid UTF-8.')->end()
            ->end()
            ->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The paths must be a list, not a map.');
        $this->reviewers($ruleKeys->arrayNode('reviewers'));

        return $tree;
    }

    private function reviewers(ArrayNodeDefinition $reviewers): void
    {
        $reviewers->isRequired()->requiresAtLeastOneElement();
        // Keys would be dropped silently, and a key that reads like a role name is easy to mistake for the role.
        $reviewers->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The reviewers must be a list, not a map.');
        $reviewer = $reviewers->arrayPrototype()->children();
        $this->text($reviewer, 'role');
        $reviewer->enumNode('tier')->isRequired()->values(array_column(Tier::cases(), 'value'));
        $reviewer->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
        $this->text($reviewer, 'prompt');
        $reviewer->integerNode('budget')->isRequired()->min(1);
    }

    private function text(NodeBuilder $reviewer, string $key): void
    {
        $reviewer->stringNode($key)->isRequired()->cannotBeEmpty()
            ->validate()->ifTrue(static fn (string $text): bool => !mb_check_encoding($text, 'UTF-8'))->thenInvalid(sprintf('The "%s" key must be valid UTF-8.', $key))->end()
            ->validate()->ifTrue(static fn (string $text): bool => 1 === preg_match('/^\s*$/u', $text))->thenInvalid(sprintf('The "%s" key must not be blank.', $key))->end();
    }

    /**
     * @param array<int|string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>}> $panels
     *
     * @return array<int|string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>}>
     */
    private function refuseUnusablePanels(array $panels): array
    {
        // Cast because PHP turns a numeric project name into an integer key.
        foreach ($panels as $project => $panel) {
            $this->refuseOtherProviders((string) $project, $panel['reviewers']);

            foreach ($panel['rules'] as $rule) {
                $this->refuseOtherProviders((string) $project, $rule['reviewers']);
            }

            $this->refuseUnreachableQuorum((string) $project, $panel);
        }

        return $panels;
    }

    /**
     * @param list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}> $reviewers
     */
    private function refuseOtherProviders(string $project, array $reviewers): void
    {
        foreach ($reviewers as $index => $reviewer) {
            $mapped = $this->tierProviders[$project][$reviewer['tier']] ?? null;

            if ($mapped !== $reviewer['provider']) {
                // A tier call reaches the provider the project maps the tier to, so a reviewer naming another one would silently run elsewhere.
                throw new InvalidArgumentException(sprintf('Reviewer %d of project "%s" names provider "%s", but the project maps tier "%s" to %s.', $index, $project, $reviewer['provider'], $reviewer['tier'], null === $mapped ? 'no provider' : '"'.$mapped.'"'));
            }
        }
    }

    /**
     * @param array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>} $panel
     */
    private function refuseUnreachableQuorum(string $project, array $panel): void
    {
        $panels = new ConfiguredPanels([$project => $panel]);

        try {
            // Rules only add reviewers, so a quorum that holds for the core panel and for the panel with every rule holds for any change.
            $panels->panelFor($project);
            $panels->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels(ChangeDescription::FULL_REVIEW_LABEL)));
        } catch (PanelConfigurationFailure $failure) {
            throw new InvalidArgumentException(sprintf('The panel of project "%s" is unusable: %s', $project, $failure->getMessage()), 0, $failure);
        }
    }
}
