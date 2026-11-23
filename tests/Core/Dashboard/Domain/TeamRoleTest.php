<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Dashboard\Domain\TeamAccount;
use Intakt\Core\Dashboard\Domain\TeamAccountState;
use Intakt\Core\Dashboard\Domain\TeamRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TeamRole::class)]
#[UsesClass(TeamAccount::class)]
final class TeamRoleTest extends TestCase
{
    public function testAccountsWithTheSameNameRemainDistinguishableInARole(): void
    {
        $accounts = [$this->account(Provider::Anthropic), $this->account(Provider::OpenAi)];

        $role = TeamRole::of(['correctness' => [Provider::Anthropic, Provider::OpenAi]], $accounts)[0];

        self::assertSame(['Default (Anthropic)', 'Default (OpenAI)'], $role->accounts);
    }

    public function testARoleWithoutAnEnabledAccountKeepsItsPlace(): void
    {
        $roles = TeamRole::of(['correctness' => [Provider::Codex], 'security' => [Provider::Anthropic]], [$this->account(Provider::Anthropic)]);

        self::assertSame([['correctness', []], ['security', ['Default (Anthropic)']]], array_map(static fn (TeamRole $role): array => [$role->name, $role->accounts], $roles));
    }

    private function account(Provider $provider): TeamAccount
    {
        return new TeamAccount(new Account($provider, Account::DEFAULT), TeamAccountState::Working, [], null);
    }
}
