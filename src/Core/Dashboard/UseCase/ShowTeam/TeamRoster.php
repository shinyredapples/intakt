<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Labels;

// The one-pool view: every account once, whatever the number of projects and tiers that use it; reset times and ceilings stay out, because they show only behind the owner session.
final readonly class TeamRoster
{
    public function __construct(
        private EnabledAccounts $accounts,
        private AccountCooldowns $cooldowns,
        private TierResolver $tiers,
        private BuildRouting $routing,
    ) {
    }

    public function now(): Team
    {
        $developer = new TeamRole(TeamRoleName::Developer, $this->developerProviders());

        return new Team($this->accountsServing($developer), [$developer]);
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

        return array_values(array_unique(array_filter($providers), SORT_REGULAR));
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

    /**
     * @return list<TeamAccount>
     */
    private function accountsServing(TeamRole $developer): array
    {
        $rows = [];

        foreach (Provider::cases() as $provider) {
            foreach ($this->accounts->of($provider) as $account) {
                $rows[] = new TeamAccount($account, $this->stateOf($account), in_array($provider, $developer->providers, true) ? [$developer->name] : []);
            }
        }

        return $rows;
    }

    // Rejected first, because a rejected key also cools down, and only the owner can bring it back.
    private function stateOf(Account $account): AccountState
    {
        return match (true) {
            $this->cooldowns->isRejected($account) => AccountState::Rejected,
            $this->cooldowns->isCoolingDown($account) => AccountState::CoolingDown,
            default => AccountState::Ready,
        };
    }
}
