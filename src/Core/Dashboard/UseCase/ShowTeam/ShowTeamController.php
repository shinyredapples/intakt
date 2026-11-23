<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\TeamAccount;
use Intakt\Core\Dashboard\Domain\TeamAccountState;
use Intakt\Core\Dashboard\Domain\TeamRole;
use Intakt\Core\Dashboard\Domain\TeamWindow;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Review\Contract\ReviewerRoles;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowTeamController
{
    public function __construct(
        private EnabledAccounts $accounts,
        private AccountCooldowns $cooldowns,
        private MeasuredUse $use,
        private ReviewerRoles $roles,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/team', name: DashboardRoutes::SHOW_TEAM, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $accounts = $this->accountsOfEveryProvider();

        return new Response($this->pages->render($request, 'dashboard/team.html.twig', [
            'accounts' => $accounts,
            'roles' => TeamRole::servedBy($accounts),
            'rail_section' => 'team',
        ]));
    }

    /**
     * @return list<TeamAccount>
     */
    private function accountsOfEveryProvider(): array
    {
        $accounts = [];

        foreach (Provider::cases() as $provider) {
            foreach ($this->accounts->of($provider) as $account) {
                $accounts[] = new TeamAccount($account, $this->stateOf($account), $this->windowsOf($account), $this->roles->servedBy($provider));
            }
        }

        return $accounts;
    }

    private function stateOf(Account $account): TeamAccountState
    {
        return match (true) {
            $this->cooldowns->isRejected($account) => TeamAccountState::KeyRejected,
            $this->cooldowns->isCoolingDown($account) => TeamAccountState::CoolingDown,
            default => TeamAccountState::Working,
        };
    }

    // A pay-per-use account has no usage window, so it lists none.
    /**
     * @return list<TeamWindow>
     */
    private function windowsOf(Account $account): array
    {
        if (!$account->provider->spendsAPlan()) {
            return [];
        }

        return array_map(
            fn (UsageWindow $window): TeamWindow => new TeamWindow(
                ucfirst(str_replace('-', ' ', $window->value)).' window',
                $this->use->of($account, $window)?->usage->usedPercent,
            ),
            UsageWindow::cases(),
        );
    }
}
