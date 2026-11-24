<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Dashboard\UseCase\ShowTeam\AccountState;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(TeamRoster::class)]
#[CoversClass(Team::class)]
#[CoversClass(TeamAccount::class)]
#[UsesClass(Account::class)]
final class TeamRosterTest extends TestCase
{
    public function testAnAccountIsListedOnceInTheOnePoolView(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default'));

        self::assertSame(['claude_code.default'], $this->accountKeys($team));
    }

    public function testEachListedAccountOfEachProviderIsListedInTheOwnersOrder(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'team', 'default')->accounts(Provider::OpenAi, 'default'));

        self::assertSame(['anthropic.team', 'anthropic.default', 'openai.default'], $this->accountKeys($team));
    }

    public function testAnInstallWithoutListedAccountsHasAnEmptyRoster(): void
    {
        self::assertSame([], $this->teamOf(new GivenInstall())->accounts);
    }

    public function testAListedAccountWithoutItsKeyIsShownAsHavingNoKey(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default', 'team')->withoutKey('anthropic.team'));

        self::assertSame([AccountState::Ready, AccountState::NoKey], array_map(static fn (TeamAccount $account): AccountState => $account->state, $team->accounts));
    }

    public function testAnExplicitDefaultAccountWithoutItsKeyIsShownAsHavingNoKey(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default')->withoutKey('anthropic.default'));

        self::assertSame(AccountState::NoKey, $team->accounts[0]->state);
    }

    public function testAnAccountWithoutItsKeyShowsNoKeyThoughItAlsoCoolsDown(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default')->withoutKey('anthropic.default')->coolingDown('anthropic.default')->rejected('anthropic.default'));

        self::assertSame(AccountState::NoKey, $team->accounts[0]->state);
    }

    public function testAFreeAccountIsReady(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default'));

        self::assertSame(AccountState::Ready, $team->accounts[0]->state);
    }

    public function testACoolingDownAccountShowsAsCoolingDown(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default')->coolingDown('anthropic.default'));

        self::assertSame(AccountState::CoolingDown, $team->accounts[0]->state);
    }

    public function testAnAccountWhoseKeyWasRejectedShowsAsRejectedThoughItAlsoCoolsDown(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default')->coolingDown('anthropic.default')->rejected('anthropic.default'));

        self::assertSame(AccountState::Rejected, $team->accounts[0]->state);
    }

    public function testTheRosterExposesNoResetTimeAndNoCeilingValue(): void
    {
        $fields = [...$this->publicFields(TeamAccount::class), ...$this->publicFields(Team::class)];

        self::assertSame(['account', 'state', 'accounts'], $fields);
    }

    private function teamOf(GivenInstall $install): Team
    {
        $listed = self::createStub(ListedAccounts::class);
        $listed->method('of')->willReturnCallback(static fn (Provider $provider): array => array_map(static fn (string $name): Account => new Account($provider, $name), $install->accounts[$provider->value] ?? []));
        $credentials = self::createStub(AccountCredentials::class);
        $credentials->method('areSet')->willReturnCallback(static fn (Account $account): bool => !in_array($account->key(), $install->withoutKey, true));
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->coolingDown, true));
        $cooldowns->method('isRejected')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->rejected, true));

        return new TeamRoster($listed, $credentials, $cooldowns)->now();
    }

    /**
     * @return list<string>
     */
    private function accountKeys(Team $team): array
    {
        return array_map(static fn (TeamAccount $account): string => $account->account->key(), $team->accounts);
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function publicFields(string $class): array
    {
        return array_map(static fn (ReflectionProperty $property): string => $property->getName(), new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC));
    }
}
