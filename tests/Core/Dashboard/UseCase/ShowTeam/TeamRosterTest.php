<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Dashboard\UseCase\ShowTeam\AccountState;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRole;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoleKind;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(TeamRoster::class)]
#[CoversClass(Team::class)]
#[CoversClass(TeamAccount::class)]
#[CoversClass(TeamRole::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(BuildRoutingNotConfigured::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelNotConfigured::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class TeamRosterTest extends TestCase
{
    public function testAnAccountIsListedOnceWhateverTheNumberOfProjectsAndTiersThatUseIt(): void
    {
        $team = $this->teamOf($this->install()->routing('beta', Tier::Strong)->tier(Tier::Standard, Provider::ClaudeCode)->accounts(Provider::ClaudeCode, 'default'));

        self::assertSame(['claude_code.default'], $this->accountKeys($team));
    }

    public function testEachListedAccountOfEachProviderIsListedInTheOwnersOrder(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default', 'team')->accounts(Provider::OpenAi, 'default'));

        self::assertSame(['anthropic.default', 'anthropic.team', 'openai.default'], $this->accountKeys($team));
    }

    public function testAListedAccountWithoutItsKeyIsShownAsHavingNoKey(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default', 'team')->withoutKey('anthropic.team'));

        self::assertSame([AccountState::Ready, AccountState::NoKey], array_map(static fn (TeamAccount $account): AccountState => $account->state, $team->accounts));
    }

    public function testAnAccountWithoutItsKeyShowsNoKeyThoughItAlsoCoolsDown(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default')->withoutKey('anthropic.default')->coolingDown('anthropic.default'));

        self::assertSame(AccountState::NoKey, $team->accounts[0]->state);
    }

    public function testAFreeAccountIsReady(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default'));

        self::assertSame(AccountState::Ready, $team->accounts[0]->state);
    }

    public function testACoolingDownAccountShowsAsCoolingDown(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default')->coolingDown('anthropic.default'));

        self::assertSame(AccountState::CoolingDown, $team->accounts[0]->state);
    }

    public function testAnAccountWhoseKeyWasRejectedShowsAsRejectedThoughItAlsoCoolsDown(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default')->coolingDown('anthropic.default')->rejected('anthropic.default'));

        self::assertSame(AccountState::Rejected, $team->accounts[0]->state);
    }

    public function testTheDeveloperListsTheStrongTiersProviderWhenTheDefaultTierIsOnAnotherVendor(): void
    {
        $install = new GivenInstall()->routing('alpha', Tier::Standard, ['src/Security/**'])->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Strong, Provider::ClaudeCode);

        $team = $this->teamOf($install->accounts(Provider::ClaudeCode, 'default')->accounts(Provider::OpenAi, 'default'));

        self::assertSame([Provider::OpenAi, Provider::ClaudeCode], $this->developer($team)->providers);
        self::assertSame(['openai.default' => [], 'claude_code.default' => ['developer']], $this->accountRoles($team));
    }

    public function testASensitivePathsTierOnAProviderTheBuildRuntimeCannotRunIsListedAsNotRunnable(): void
    {
        $install = new GivenInstall()->routing('alpha', Tier::Standard, ['src/Security/**'])->tier(Tier::Standard, Provider::ClaudeCode)->tier(Tier::Strong, Provider::OpenAi);

        $developer = $this->developer($this->teamOf($install));

        self::assertSame([Provider::ClaudeCode, Provider::OpenAi], $developer->providers);
        self::assertSame([Provider::OpenAi], $developer->notRunnable);
    }

    public function testAnAccountOfAProviderTheBuildRuntimeCannotRunDoesNotServeTheDeveloper(): void
    {
        $team = $this->teamOf(new GivenInstall()->routing('alpha', Tier::Standard)->tier(Tier::Standard, Provider::OpenAi)->accounts(Provider::OpenAi, 'default'));

        self::assertSame(['openai.default' => []], $this->accountRoles($team));
    }

    public function testEveryProviderTheBuildRuntimeCannotRunIsListedAsNotRunnable(): void
    {
        $developer = $this->developer($this->teamOf(new GivenInstall()->routing('alpha', Tier::Standard)->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Strong, Provider::Anthropic)));

        self::assertSame([Provider::OpenAi, Provider::Anthropic], $developer->notRunnable);
    }

    public function testTheBuildRuntimesProviderIsNotListedAsNotRunnable(): void
    {
        $team = $this->teamOf(new GivenInstall()->routing('alpha', Tier::Strong)->tier(Tier::Strong, Provider::ClaudeCode));

        self::assertSame([], $this->developer($team)->notRunnable);
    }

    public function testAProjectWhoseDefaultTierIsStrongListsItsProviderOnce(): void
    {
        $team = $this->teamOf(new GivenInstall()->routing('alpha', Tier::Strong)->tier(Tier::Strong, Provider::ClaudeCode));

        self::assertSame([Provider::ClaudeCode], $this->developer($team)->providers);
    }

    public function testATierALabelCanPickIsListedWhenTheProjectMapsIt(): void
    {
        $team = $this->teamOf(new GivenInstall()->routing('alpha', Tier::Standard)->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Light, Provider::ClaudeCode));

        self::assertContains(Provider::ClaudeCode, $this->developer($team)->providers);
    }

    public function testAProjectWithoutBuildRoutingIsLeftOutOfTheDeveloperRoleWithoutFailingTheRoster(): void
    {
        $team = $this->teamOf(new GivenInstall()->unroutedProject('unrouted')->routing('alpha', Tier::Strong)->tier(Tier::Strong, Provider::ClaudeCode));

        self::assertSame([Provider::ClaudeCode], $this->developer($team)->providers);
    }

    public function testAnInstallWithoutProjectsHasADeveloperWithoutProvidersAndNoReviewer(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default'));

        self::assertSame(['developer'], array_map(static fn (TeamRole $role): string => $role->label(), $team->roles));
        self::assertSame([], $this->developer($team)->providers);
        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testAnAccountOfAProviderNoRoleUsesServesNoRole(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Codex, 'default'));

        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testEachReviewerRoleOfAProjectsPanelIsListedWithItsProvider(): void
    {
        $team = $this->teamOf($this->install()->reviewer('alpha', 'correctness', Provider::Anthropic)->reviewer('alpha', 'security', Provider::OpenAi));

        self::assertSame(['developer' => [Provider::OpenAi, Provider::ClaudeCode], 'reviewer correctness' => [Provider::Anthropic], 'reviewer security' => [Provider::OpenAi]], $this->rolesWithProviders($team));
    }

    public function testAReviewerOnlyAPanelRuleAddsIsListed(): void
    {
        $team = $this->teamOf($this->install()->reviewer('alpha', 'correctness', Provider::Anthropic)->ruleReviewer('alpha', 'security', Provider::OpenAi));

        self::assertArrayHasKey('reviewer security', $this->rolesWithProviders($team));
    }

    public function testAReviewerRoleOfTwoProjectsIsListedOnceWithTheProvidersOfBoth(): void
    {
        $install = $this->install()->routing('beta', Tier::Standard)->reviewer('alpha', 'correctness', Provider::Anthropic)->reviewer('beta', 'correctness', Provider::OpenAi);

        self::assertSame([Provider::Anthropic, Provider::OpenAi], $this->rolesWithProviders($this->teamOf($install))['reviewer correctness']);
    }

    public function testAnAccountServesEveryRoleThatRunsOnItsProvider(): void
    {
        $install = $this->install()->reviewer('alpha', 'correctness', Provider::ClaudeCode)->reviewer('alpha', 'security', Provider::OpenAi);

        $team = $this->teamOf($install->accounts(Provider::ClaudeCode, 'default')->accounts(Provider::OpenAi, 'default'));

        self::assertSame(['openai.default' => ['reviewer security'], 'claude_code.default' => ['developer', 'reviewer correctness']], $this->accountRoles($team));
    }

    public function testTheRosterExposesNoResetTimeAndNoCeilingValue(): void
    {
        $fields = [...$this->publicFields(TeamAccount::class), ...$this->publicFields(TeamRole::class), ...$this->publicFields(Team::class)];

        self::assertSame(['account', 'state', 'roles', 'kind', 'name', 'providers', 'notRunnable', 'accounts', 'roles'], $fields);
    }

    public function testTheDeveloperRoleIsOfTheDeveloperKind(): void
    {
        self::assertSame(TeamRoleKind::Developer, $this->developer($this->teamOf($this->install()))->kind);
    }

    private function install(): GivenInstall
    {
        return new GivenInstall()->routing('alpha', Tier::Standard)->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Strong, Provider::ClaudeCode);
    }

    private function teamOf(GivenInstall $install): Team
    {
        $listed = self::createStub(ListedAccounts::class);
        $listed->method('of')->willReturnCallback(static fn (Provider $provider): array => array_map(static fn (string $name): Account => new Account($provider, $name), $install->accounts[$provider->value] ?? []));
        $enabled = self::createStub(EnabledAccounts::class);
        $enabled->method('of')->willReturnCallback(static fn (Provider $provider): array => array_values(array_filter($listed->of($provider), static fn (Account $account): bool => !in_array($account->key(), $install->withoutKey, true))));
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->coolingDown, true));
        $cooldowns->method('isRejected')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->rejected, true));
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames(...$install->projects));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => isset($install->tiers[$tier->value]) ? new ResolvedModel($install->tiers[$tier->value], 'model-'.$tier->value) : throw new TierNotMapped($project, $tier));

        return new TeamRoster($listed, $enabled, $cooldowns, $tiers, new ConfiguredBuildRouting($install->routing, new ModelRouting()), new ConfiguredPanels($install->panels))->now();
    }

    /**
     * @return list<string>
     */
    private function accountKeys(Team $team): array
    {
        return array_map(static fn (TeamAccount $account): string => $account->account->key(), $team->accounts);
    }

    private function developer(Team $team): TeamRole
    {
        return $team->roles[0];
    }

    /**
     * @return array<string, list<string>>
     */
    private function accountRoles(Team $team): array
    {
        return array_combine($this->accountKeys($team), array_map(static fn (TeamAccount $account): array => array_map(static fn (TeamRole $role): string => $role->label(), $account->roles), $team->accounts));
    }

    /**
     * @return array<string, list<Provider>>
     */
    private function rolesWithProviders(Team $team): array
    {
        return array_combine(array_map(static fn (TeamRole $role): string => $role->label(), $team->roles), array_map(static fn (TeamRole $role): array => $role->providers, $team->roles));
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
