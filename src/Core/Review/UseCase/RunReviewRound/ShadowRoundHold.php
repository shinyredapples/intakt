<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ShadowStartGuard;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;

// Per provider of the panel, because a reviewer fails over between a provider's accounts, so one account below the ceiling is where it runs.
final readonly class ShadowRoundHold
{
    public function __construct(
        private ProjectModes $modes,
        private ShadowStartGuard $guard,
        private EnabledAccounts $accounts,
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
            $decision = $this->bestDecisionFor($provider);

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

    // A provider without an enabled account starts: the vendor names that failure itself.
    private function bestDecisionFor(Provider $provider): StartDecision
    {
        $accounts = $this->accounts->of($provider);
        $decisions = array_map(fn (Account $account): StartDecision => $this->guard->decide($account, 0), $accounts);

        return array_find($decisions, static fn (StartDecision $decision): bool => $decision->admits()) ?? array_first($decisions) ?? StartDecision::Start;
    }
}
