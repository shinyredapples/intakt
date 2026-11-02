<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\ShadowStartGuard;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;

final readonly class ShadowRoundHold
{
    public function __construct(
        private ProjectModes $modes,
        private ShadowStartGuard $guard,
        private RoundLedger $ledger,
        private TierResolver $tiers,
    ) {
    }

    /**
     * @throws ShadowCeilingReached
     */
    public function refuseAboveTheCeiling(RunReviewRound $command): void
    {
        $project = $command->target->subject->project;

        if (ProjectMode::Shadow !== $this->modes->of($project)) {
            return;
        }

        foreach ($this->providersOf($command) as $provider) {
            $decision = $this->guard->decide($provider);

            if (!$decision->admits()) {
                $this->ledger->shadowRoundHeld($command, $provider, $decision);

                throw ShadowCeilingReached::by($project, $provider->value, $decision);
            }
        }
    }

    /**
     * @return list<Provider>
     */
    private function providersOf(RunReviewRound $command): array
    {
        $project = $command->target->subject->project;
        $providers = [];

        foreach ($command->panel->reviewers as $reviewer) {
            $providers[] = $reviewer->model->provider;
            // A reviewer answers on a substitute as soon as its own provider is out, so the substitute's accounts must be below the ceilings too.
            array_push($providers, ...$this->substituteProvidersOf($project, $reviewer));
        }

        return array_values(array_unique($providers, SORT_REGULAR));
    }

    /**
     * @return list<Provider>
     */
    private function substituteProvidersOf(string $project, PanelReviewer $reviewer): array
    {
        return array_map(static fn (ResolvedModel $substitute): Provider => $substitute->provider, $this->tiers->substitutes($project, $reviewer->model->tier));
    }
}
