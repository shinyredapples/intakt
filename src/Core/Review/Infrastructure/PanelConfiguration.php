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
use Intakt\Core\Review\Contract\ReviewerRole;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Its own tree because the projects tree of the AI module refuses keys it does not know; each tree gets only its part of a project.
final readonly class PanelConfiguration implements ConfigurationInterface
{
    // The user guide, the plain-words guides and the README by default, because those are the pages people read (docs/design/05-architecture/07-reviews.md#the-technical-writer).
    private const array TECHNICAL_WRITER_PATHS = ['docs/user/**', 'docs/guide/**', 'README.md'];

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
        $this->technicalWriter($panel->arrayNode('technical_writer')->children());

        return $tree;
    }

    /**
     * @param array<int|string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>, technical_writer?: array{tier: string, provider: string, prompt: non-empty-string, budget: positive-int, paths: list<string>}}> $panels
     *
     * @return array<int|string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>, technical_writer?: array{tier: string, provider: string, prompt: non-empty-string, budget: positive-int, paths: list<string>}}>
     */
    private function refuseUnusablePanels(array $panels): array
    {
        // Cast because PHP turns a numeric project name into an integer key.
        foreach ($panels as $project => $panel) {
            $this->refuseOtherProviders((string) $project, $panel['reviewers']);

            foreach ($panel['rules'] as $rule) {
                $this->refuseOtherProviders((string) $project, $rule['reviewers']);
            }

            if (isset($panel['technical_writer'])) {
                $this->refuseOtherProvider((string) $project, 'The technical writer', $panel['technical_writer']);
            }

            $this->refuseUnreachableQuorum((string) $project, $panel);
        }

        return $panels;
    }

    private function reviewers(ArrayNodeDefinition $reviewers): void
    {
        $reviewers->isRequired()->requiresAtLeastOneElement();
        // Keys would be dropped silently, and a key that reads like a role name is easy to mistake for the role.
        $reviewers->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The reviewers must be a list, not a map.');
        $prototype = $reviewers->arrayPrototype();
        // Reserved, because the role name puts a reviewer in the docs lane, which only the technical_writer section's trigger may add.
        $prototype->validate()->ifTrue(static fn (array $reviewer): bool => ReviewerRole::TECHNICAL_WRITER === ($reviewer['role'] ?? null))->thenInvalid(sprintf('The role "%s" is reserved for the technical_writer section.', ReviewerRole::TECHNICAL_WRITER));
        $reviewer = $prototype->children();
        $this->text($reviewer, 'role');
        $this->member($reviewer);
    }

    private function text(NodeBuilder $reviewer, string $key): void
    {
        $reviewer->stringNode($key)->isRequired()->cannotBeEmpty()
            ->validate()->ifTrue(static fn (string $text): bool => !mb_check_encoding($text, 'UTF-8'))->thenInvalid(sprintf('The "%s" key must be valid UTF-8.', $key))->end()
            ->validate()->ifTrue(static fn (string $text): bool => 1 === preg_match('/^\s*$/u', $text))->thenInvalid(sprintf('The "%s" key must not be blank.', $key))->end();
    }

    private function member(NodeBuilder $member): void
    {
        $member->enumNode('tier')->isRequired()->values(array_column(Tier::cases(), 'value'));
        $member->enumNode('provider')->isRequired()->values(array_column(Provider::cases(), 'value'));
        $this->text($member, 'prompt');
        $member->integerNode('budget')->isRequired()->min(1);
    }

    private function technicalWriter(NodeBuilder $writer): void
    {
        $this->member($writer);
        $writer->arrayNode('paths')->defaultValue(self::TECHNICAL_WRITER_PATHS)->requiresAtLeastOneElement()
            ->stringPrototype()->cannotBeEmpty()
                ->validate()->ifTrue(static fn (string $glob): bool => !mb_check_encoding($glob, 'UTF-8'))->thenInvalid('A path pattern must be valid UTF-8.')->end()
            ->end()
            ->validate()->ifTrue(static fn (array $list): bool => !array_is_list($list))->thenInvalid('The paths must be a list, not a map.');
    }

    /**
     * @param list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}> $reviewers
     */
    private function refuseOtherProviders(string $project, array $reviewers): void
    {
        foreach ($reviewers as $index => $reviewer) {
            $this->refuseOtherProvider($project, sprintf('Reviewer %d', $index), $reviewer);
        }
    }

    /**
     * @param array{tier: string, provider: string} $member
     */
    private function refuseOtherProvider(string $project, string $who, array $member): void
    {
        $mapped = $this->tierProviders[$project][$member['tier']] ?? null;

        if ($mapped !== $member['provider']) {
            // A tier call reaches the provider the project maps the tier to, so a reviewer naming another one would silently run elsewhere.
            throw new InvalidArgumentException(sprintf('%s of project "%s" names provider "%s", but the project maps tier "%s" to %s.', $who, $project, $member['provider'], $member['tier'], null === $mapped ? 'no provider' : '"'.$mapped.'"'));
        }
    }

    /**
     * @param array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules: list<array{min_lines?: positive-int, paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>, technical_writer?: array{tier: string, provider: string, prompt: non-empty-string, budget: positive-int, paths: list<string>}} $panel
     */
    private function refuseUnreachableQuorum(string $project, array $panel): void
    {
        // No tier mapping, because only `review:full` is asked and that label moves no reviewer to another tier.
        $panels = new ConfiguredPanels([$project => $panel], []);

        try {
            // Rules only add reviewers, so a quorum that holds for the core panel and for the panel with every rule holds for any change.
            $panels->panelFor($project);
            $panels->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels(ChangeDescription::FULL_REVIEW_LABEL)));
        } catch (PanelConfigurationFailure $failure) {
            throw new InvalidArgumentException(sprintf('The panel of project "%s" is unusable: %s', $project, $failure->getMessage()), 0, $failure);
        }
    }
}
