<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\Domain\TechnicalWriterTrigger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredPanels implements Panels
{
    /**
     * @param array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules?: list<array{min_lines?: positive-int, paths?: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>, technical_writer?: array{tier: string, provider: string, prompt: non-empty-string, budget: positive-int, paths: list<string>}}> $panels
     * @param array<string, array{tiers: array<string, array{provider: string}>}>                                                                                                                                                                                                                                                                                                                                                                                                                                      $projects
     */
    public function __construct(
        #[Autowire(param: ReviewServices::PANELS)]
        private array $panels,
        #[Autowire(param: AiServices::PROJECTS)]
        private array $projects,
    ) {
    }

    public function panelFor(string $project): Panel
    {
        return $this->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels()));
    }

    public function panelForChange(string $project, ChangeDescription $change): Panel
    {
        $panel = $this->panels[$project] ?? throw new PanelNotConfigured($project);
        $reviewers = $panel['reviewers'];

        // Rules only add to the core panel, so no change can shrink it.
        foreach ($panel['rules'] ?? [] as $rule) {
            if ($change->wantsFullReview() || $this->matches($rule, $change)) {
                $reviewers = [...$reviewers, ...$rule['reviewers']];
            }
        }

        $writer = $panel['technical_writer'] ?? null;

        if (null !== $writer && new TechnicalWriterTrigger($writer['paths'])->matches($change)) {
            $reviewers[] = ['role' => ReviewerRole::TECHNICAL_WRITER, 'tier' => $writer['tier'], 'provider' => $writer['provider'], 'prompt' => $writer['prompt'], 'budget' => $writer['budget']];
        }

        $quorum = QuorumPolicy::from($panel['quorum']);

        return new Panel(
            array_map(fn (array $reviewer): PanelReviewer => $this->reviewer($reviewer, $this->tierOf($reviewer, $this->onOwnProvider($project, $reviewer, $change->startTier()), $quorum)), $reviewers),
            $quorum,
        );
    }

    /**
     * @param array{min_lines?: positive-int, paths?: list<string>} $rule
     */
    private function matches(array $rule, ChangeDescription $change): bool
    {
        if (isset($rule['min_lines']) && $change->lines >= $rule['min_lines']) {
            return true;
        }

        foreach ($rule['paths'] ?? [] as $glob) {
            $pattern = new PathGlob($glob);

            if ([] !== array_filter([...$change->paths], $pattern->matches(...))) {
                return true;
            }
        }

        return false;
    }

    // Only a tier the project maps to the reviewer's own provider, because a call reaches the provider its tier maps to: another one would run the reviewer elsewhere than the panel says (two independent vendors could become one), and a tier without a model would refuse the round.
    /**
     * @param array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int} $reviewer
     */
    private function onOwnProvider(string $project, array $reviewer, ?Tier $labelled): ?Tier
    {
        if (!$labelled instanceof Tier) {
            return null;
        }

        $mapped = $this->projects[$project]['tiers'][$labelled->value]['provider'] ?? null;

        return $mapped === $reviewer['provider'] ? $labelled : null;
    }

    // A strong reviewer keeps its tier under a quorum that needs a strong vote, because that quorum is a safety rule and safety rules beat labels.
    /**
     * @param array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int} $reviewer
     */
    private function tierOf(array $reviewer, ?Tier $labelled, QuorumPolicy $quorum): Tier
    {
        $configured = Tier::from($reviewer['tier']);

        if (!$labelled instanceof Tier || ($quorum->needsStrongVote() && Tier::Strong === $configured)) {
            return $configured;
        }

        return $labelled;
    }

    /**
     * @param array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int} $reviewer
     */
    private function reviewer(array $reviewer, Tier $tier): PanelReviewer
    {
        return new PanelReviewer(
            new ReviewerRole($reviewer['role'], $reviewer['prompt']),
            new ReviewerModel($tier, Provider::from($reviewer['provider'])),
            $reviewer['budget'],
        );
    }
}
