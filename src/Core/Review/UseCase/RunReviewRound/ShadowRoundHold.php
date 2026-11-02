<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\ShadowStartGuard;

final readonly class ShadowRoundHold
{
    public function __construct(
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
            array_push($providers, ...$this->substituteProvidersOf($project, $reviewer->model->tier));
        }

        array_push($providers, ...$this->judgeProvidersOf($project));

        return array_values(array_unique($providers, SORT_REGULAR));
    }

    /**
     * @return list<Provider>
     */
    private function substituteProvidersOf(string $project, Tier $tier): array
    {
        return array_map(static fn (ResolvedModel $substitute): Provider => $substitute->provider, $this->tiers->substitutes($project, $tier));
    }

    // The judge runs on the light tier once a reviewer reports findings, so its accounts are spent by the round as well.
    /**
     * @return list<Provider>
     */
    private function judgeProvidersOf(string $project): array
    {
        try {
            return [$this->tiers->configured($project, Tier::Light)->provider, ...$this->substituteProvidersOf($project, Tier::Light)];
        } catch (TierNotMapped) {
            // Left out because without a light tier the judge never reaches an account and names that failure itself.
            return [];
        }
    }
}
