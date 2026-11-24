<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\Provider;

// The one-pool view: every account once, whatever the number of projects and tiers that use it; reset times and ceilings stay out, because they show only behind the owner session.
final readonly class TeamRoster
{
    public function __construct(
        private ListedAccounts $listed,
        private AccountCredentials $credentials,
        private AccountCooldowns $cooldowns,
    ) {
    }

    public function now(): Team
    {
        $rows = [];

        foreach (Provider::cases() as $provider) {
            foreach ($this->listed->of($provider) as $account) {
                $rows[] = new TeamAccount($account, $this->stateOf($account));
            }
        }

        return new Team($rows);
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
