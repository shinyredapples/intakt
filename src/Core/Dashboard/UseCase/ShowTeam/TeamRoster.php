<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\BuildRuntime;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelConfigurationFailure;
use Intakt\Core\Review\Contract\Panels;

// The one-pool view: every account once, whatever the number of projects and tiers that use it; reset times and ceilings stay out, because they show only behind the owner session.
final readonly class TeamRoster
{
    public function __construct(
        private ListedAccounts $listed,
        private EnabledAccounts $enabled,
        private AccountCooldowns $cooldowns,
        private TierResolver $tiers,
        private BuildRouting $routing,
        private Panels $panels,
    ) {
    }

    public function now(): Team
    {
        $roles = [TeamRole::developer(...$this->developerProviders()), ...$this->reviewerRoles()];

        return new Team($this->accountsServing($roles), $roles);
    }

    /**
     * @return list<Provider>
     */
    private function developerProviders(): array
    {
        $providers = [];

        foreach ($this->tiers->projects() as $project) {
            foreach ($this->buildTiersOf($project) as $tier) {
                $providers[] = $this->providerOf($project, $tier);
            }
        }

        // Only the build runtime's provider, because a build resolved to any other fails before it reaches an account.
        return array_values(array_unique(array_filter($providers, static fn (?Provider $provider): bool => BuildRuntime::PROVIDER === $provider), SORT_REGULAR));
    }

    // A `model:` label can pick every tier, so the strong tier that sensitive paths and a confirmed escalation route to is among these.
    /**
     * @return list<Tier>
     */
    private function buildTiersOf(string $project): array
    {
        $facts = [new BuildFacts(null, new Paths()), ...array_map(static fn (Tier $tier): BuildFacts => BuildFacts::fromLabels(new Labels('model:'.$tier->value), new Paths()), Tier::cases())];

        try {
            return array_map(fn (BuildFacts $fact): Tier => $this->routing->tierFor($project, $fact)->tier, $facts);
        } catch (BuildRoutingNotConfigured) {
            // Left out, because a project the build never routes has no developer to show.
            return [];
        }
    }

    // Null for a tier the project maps no model to, because a build sent there fails before it reaches an account.
    private function providerOf(string $project, Tier $tier): ?Provider
    {
        try {
            return $this->tiers->configured($project, $tier)->provider;
        } catch (TierResolutionFailure) {
            return null;
        }
    }

    // A full review's panel, because it holds the core reviewers and every rule's; one role per name, on every provider any project gives it.
    /**
     * @return list<TeamRole>
     */
    private function reviewerRoles(): array
    {
        $providers = [];

        foreach ($this->tiers->projects() as $project) {
            foreach ($this->fullPanelOf($project)->reviewers ?? [] as $reviewer) {
                $providers[$reviewer->role->name][] = $reviewer->model->provider;
            }
        }

        return array_map(
            static fn (string $name, array $of): TeamRole => new TeamRole(TeamRoleKind::Reviewer, $name, array_values(array_unique($of, SORT_REGULAR))),
            array_map(strval(...), array_keys($providers)),
            $providers,
        );
    }

    // Null for a project without a review panel, because it has no reviewer to show.
    private function fullPanelOf(string $project): ?Panel
    {
        try {
            return $this->panels->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels(ChangeDescription::FULL_REVIEW_LABEL)));
        } catch (PanelConfigurationFailure|GlobCannotBeEvaluated) {
            return null;
        }
    }

    /**
     * @param list<TeamRole> $roles
     *
     * @return list<TeamAccount>
     */
    private function accountsServing(array $roles): array
    {
        $rows = [];

        foreach (Provider::cases() as $provider) {
            $enabled = array_map(static fn (Account $account): string => $account->key(), $this->enabled->of($provider));

            foreach ($this->listed->of($provider) as $account) {
                $rows[] = new TeamAccount($account, $this->stateOf($account, $enabled), array_values(array_filter($roles, static fn (TeamRole $role): bool => $role->servesOn($provider))));
            }
        }

        return $rows;
    }

    // A missing key first, because such an account never runs; then rejected, because a rejected key also cools down and only the owner can bring it back.
    /**
     * @param list<string> $enabled
     */
    private function stateOf(Account $account, array $enabled): AccountState
    {
        return match (true) {
            !in_array($account->key(), $enabled, true) => AccountState::NoKey,
            $this->cooldowns->isRejected($account) => AccountState::Rejected,
            $this->cooldowns->isCoolingDown($account) => AccountState::CoolingDown,
            default => AccountState::Ready,
        };
    }
}
