<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
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
        $providers = array_map(static fn (PanelReviewer $reviewer): Provider => $reviewer->model->provider, $command->panel->reviewers);

        return array_values(array_unique($providers, SORT_REGULAR));
    }
}
