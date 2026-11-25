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

// The one-pool view: every account once, whatever the number of projects and tiers that use it; reset times and ceilings stay out, because they show only behind the owner session.
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
    ) {
    }

    public function now(): Team
    {
        $roles = $this->rolesByProvider();
        $rows = [];

        foreach (Provider::cases() as $provider) {
            foreach ($this->listed->of($provider) as $account) {
                $rows[] = new TeamAccount($account, $this->stateOf($account), $roles[$provider->value] ?? []);
            }
        }

        return new Team($rows);
    }

    /**
     * @return array<string, list<TeamRole>>
     */
    private function rolesByProvider(): array
    {
        $roles = [];

        foreach ($this->tiers->projects() as $project) {
            foreach ($this->developerProvidersOf($project) as $provider) {
                $roles = $this->withRole($roles, $provider, new TeamRole(self::DEVELOPER, $this->buildRuntimeRuns($provider)));
            }

            foreach ($this->reviewersOf($project) as $reviewer) {
                $roles = $this->withRole($roles, $reviewer->model->provider, new TeamRole('Reviewer: '.$reviewer->role->name));
            }
        }

        return $roles;
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

    // The default tier and the strong tier, because a sensitive path, a confirmed escalation or a setback all route to the strong one and the routing keeps its rules to itself; a project the routing or the tiers cannot answer for adds no role, because the build refuses it too.
    /**
     * @return list<Provider>
     */
    private function developerProvidersOf(string $project): array
    {
        try {
            $plain = $this->routing->tierFor($project, new BuildFacts(null, new Paths()))->tier;
            $escalated = $this->routing->tierFor($project, new BuildFacts(null, new Paths(), null, EscalationOutcome::Confirmed))->tier;

            return array_map(fn (Tier $tier): Provider => $this->tiers->configured($project, $tier)->provider, [$plain, $escalated]);
        } catch (BuildRoutingNotConfigured|TierResolutionFailure) {
            return [];
        }
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

    // The same rule the build applies when it picks the model: the builder is the Claude Code CLI.
    private function buildRuntimeRuns(Provider $provider): bool
    {
        return Provider::ClaudeCode === $provider;
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
