<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredPanels implements Panels
{
    /**
     * @param array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules?: list<array{min_lines?: positive-int, paths?: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>}> $panels
     */
    public function __construct(
        #[Autowire(param: ReviewServices::PANELS)]
        private array $panels,
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

        $quorum = QuorumPolicy::from($panel['quorum']);

        return new Panel(
            array_map(fn (array $reviewer): PanelReviewer => $this->reviewer($reviewer, $this->tierOf($reviewer, $change, $quorum)), $reviewers),
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

    // A strong reviewer keeps its tier under a quorum that needs a strong vote, because that quorum is a safety rule and safety rules beat labels.
    /**
     * @param array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int} $reviewer
     */
    private function tierOf(array $reviewer, ChangeDescription $change, QuorumPolicy $quorum): Tier
    {
        $configured = Tier::from($reviewer['tier']);
        $labelled = $change->startTier();

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
