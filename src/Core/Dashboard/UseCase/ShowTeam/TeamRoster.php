<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\AccountPicks;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PanelConfigurationFailure;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;

// The one-pool view: every account once, whatever the number of projects and tiers that use it; the account rows carry no reset time or ceiling, because those show only behind the owner session. The last pick of each provider carries its reset times, because a start that found no account must name the earliest reset (T81), and only the owner's console reads it today.
final readonly class TeamRoster
{
    private const string DEVELOPER = 'Developer';

    public function __construct(
        private ListedAccounts $listed,
        private AccountCredentials $credentials,
        private AccountCooldowns $cooldowns,
        private BuildRouting $routing,
        private TierResolver $tiers,
        private Panels $panels,
        private AccountPicks $picks,
    ) {
    }

    public function now(): Team
    {
        $roles = $this->rolesByProvider();
        $rows = [];
        $picks = [];

        foreach (Provider::cases() as $provider) {
            $accounts = $this->listed->of($provider);
            foreach ($accounts as $account) {
                $rows[] = new TeamAccount($account, $this->stateOf($account), $roles[$provider->value] ?? []);
            }

            $pick = [] === $accounts ? null : $this->picks->latestOf($provider);
            if ($pick instanceof AccountPick) {
                $picks[] = $pick;
            }
        }

        return new Team($rows, $picks);
    }

    /**
     * @return array<string, list<TeamRole>>
     */
    private function rolesByProvider(): array
    {
        $roles = [];

        foreach ($this->tiers->projects() as $project) {
            foreach ($this->developerProvidersOf($project) as $provider) {
                $roles = $this->withRole($roles, $provider, new TeamRole(self::DEVELOPER, $this->buildRuntimeFor($provider)));
            }

            foreach ($this->reviewersOf($project) as $reviewer) {
                $roles = $this->withRole($roles, $reviewer->model->provider, new TeamRole('Reviewer: '.$reviewer->role->name));
            }
        }

        return $roles;
    }

    // Each tier resolved on its own, because one unmapped tier fails only the builds routed to it, never the builds of the project's other tiers; a project the routing cannot answer for adds no role, because the build refuses it too.
    /**
     * @return list<Provider>
     */
    private function developerProvidersOf(string $project): array
    {
        try {
            $tiers = $this->reachableTiersOf($project);
        } catch (BuildRoutingNotConfigured) {
            return [];
        }

        $providers = [];

        foreach ($tiers as $tier) {
            try {
                $providers[] = $this->tiers->configured($project, $tier)->provider;
            } catch (TierResolutionFailure) {
                continue;
            }
        }

        return $providers;
    }

    // The routing keeps its rules to itself, so it is asked for the default, for a confirmed escalation (where a sensitive path and a setback route too) and for each tier a label can name.
    /**
     * @return array<string, Tier>
     *
     * @throws BuildRoutingNotConfigured
     */
    private function reachableTiersOf(string $project): array
    {
        $facts = [
            new BuildFacts(null, new Paths()),
            new BuildFacts(null, new Paths(), null, EscalationOutcome::Confirmed),
            ...array_map(static fn (Tier $labelled): BuildFacts => new BuildFacts($labelled, new Paths()), Tier::cases()),
        ];
        $tiers = [];

        foreach ($facts as $fact) {
            $tier = $this->routing->tierFor($project, $fact)->tier;
            $tiers[$tier->value] = $tier;
        }

        return $tiers;
    }

    // Only the Claude Code CLI, because the build job runs its builder through that CLI alone, as ChooseBuildModel refuses any other provider.
    private function buildRuntimeFor(Provider $provider): BuildRuntime
    {
        return Provider::ClaudeCode === $provider ? BuildRuntime::Runs : BuildRuntime::CannotRun;
    }

    /**
     * @param array<string, list<TeamRole>> $roles
     *
     * @return array<string, list<TeamRole>>
     */
    private function withRole(array $roles, Provider $provider, TeamRole $role): array
    {
        $known = $roles[$provider->value] ?? [];

        if (!array_any($known, static fn (TeamRole $held): bool => $held->name === $role->name)) {
            $roles[$provider->value] = [...$known, $role];
        }

        return $roles;
    }

    // The change asks for a full review, because the panel then lists every reviewer a rule can add.
    /**
     * @return list<PanelReviewer>
     */
    private function reviewersOf(string $project): array
    {
        try {
            return $this->panels->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels(ChangeDescription::FULL_REVIEW_LABEL)))->reviewers;
        } catch (PanelConfigurationFailure) {
            return [];
        }
    }

    // A missing key first, because such an account never runs; then rejected, because a rejected key also cools down and only the owner can bring it back.
    private function stateOf(Account $account): AccountState
    {
        return match (true) {
            !$this->credentials->areSet($account) => AccountState::NoKey,
            $this->cooldowns->isRejected($account) => AccountState::Rejected,
            $this->cooldowns->isCoolingDown($account) => AccountState::CoolingDown,
            default => AccountState::Ready,
        };
    }
}
