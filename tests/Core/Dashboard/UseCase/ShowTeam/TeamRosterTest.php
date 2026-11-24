<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
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
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoleName;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
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
final class TeamRosterTest extends TestCase
{
    public function testAnAccountIsListedOnceWhateverTheNumberOfProjectsAndTiersThatUseIt(): void
    {
        $team = $this->teamOf($this->install()->routing('beta', Tier::Strong)->tier(Tier::Standard, Provider::Anthropic)->accounts(Provider::Anthropic, 'default'));

        self::assertSame(['anthropic.default'], $this->accountKeys($team));
    }

    public function testEachEnabledAccountOfEachProviderIsListedInTheOwnersOrder(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Anthropic, 'default', 'team')->accounts(Provider::OpenAi, 'default'));

        self::assertSame(['anthropic.default', 'anthropic.team', 'openai.default'], $this->accountKeys($team));
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

    public function testAProjectWhoseSensitivePathsRouteToTheStrongTierOnAnotherVendorListsBothVendorsForTheDeveloper(): void
    {
        $install = new GivenInstall()->routing('alpha', Tier::Standard, ['src/Security/**'])->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Strong, Provider::Anthropic);

        $team = $this->teamOf($install->accounts(Provider::Anthropic, 'default')->accounts(Provider::OpenAi, 'default'));

        self::assertSame([Provider::OpenAi, Provider::Anthropic], $this->developer($team)->providers);
        self::assertSame([[TeamRoleName::Developer], [TeamRoleName::Developer]], array_map(static fn (TeamAccount $account): array => $account->roles, $team->accounts));
    }

    public function testAProjectWhoseDefaultTierIsStrongListsItsProviderOnce(): void
    {
        $team = $this->teamOf(new GivenInstall()->routing('alpha', Tier::Strong)->tier(Tier::Strong, Provider::Anthropic));

        self::assertSame([Provider::Anthropic], $this->developer($team)->providers);
    }

    public function testATierALabelCanPickIsListedWhenTheProjectMapsIt(): void
    {
        $team = $this->teamOf($this->install()->tier(Tier::Light, Provider::ClaudeCode));

        self::assertSame([Provider::OpenAi, Provider::Anthropic, Provider::ClaudeCode], $this->developer($team)->providers);
    }

    public function testAProjectWithoutBuildRoutingIsLeftOutOfTheDeveloperRoleWithoutFailingTheRoster(): void
    {
        $team = $this->teamOf(new GivenInstall()->unroutedProject('unrouted')->routing('alpha', Tier::Strong)->tier(Tier::Strong, Provider::Anthropic));

        self::assertSame([Provider::Anthropic], $this->developer($team)->providers);
    }

    public function testAnInstallWithoutProjectsHasADeveloperWithoutProviders(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Anthropic, 'default'));

        self::assertSame([], $this->developer($team)->providers);
        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testAnAccountOfAProviderNoRoleUsesServesNoRole(): void
    {
        $team = $this->teamOf($this->install()->accounts(Provider::Codex, 'default'));

        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testTheRosterExposesNoResetTimeAndNoCeilingValue(): void
    {
        $fields = [...$this->publicFields(TeamAccount::class), ...$this->publicFields(TeamRole::class), ...$this->publicFields(Team::class)];

        self::assertSame(['account', 'state', 'roles', 'name', 'providers', 'accounts', 'roles'], $fields);
    }

    // A project that builds on the standard tier with OpenAI and has its strong tier on Anthropic.
    private function install(): GivenInstall
    {
        return new GivenInstall()->routing('alpha', Tier::Standard)->tier(Tier::Standard, Provider::OpenAi)->tier(Tier::Strong, Provider::Anthropic);
    }

    private function teamOf(GivenInstall $install): Team
    {
        $enabled = $this->createStub(EnabledAccounts::class);
        $enabled->method('of')->willReturnCallback(static fn (Provider $provider): array => array_map(static fn (string $name): Account => new Account($provider, $name), $install->accounts[$provider->value] ?? []));
        $cooldowns = $this->createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->coolingDown, true));
        $cooldowns->method('isRejected')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), $install->rejected, true));
        $tiers = $this->createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames(...$install->projects));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => isset($install->tiers[$tier->value]) ? new ResolvedModel($install->tiers[$tier->value], 'model-'.$tier->value) : throw new TierNotMapped($project, $tier));

        return new TeamRoster($enabled, $cooldowns, $tiers, new ConfiguredBuildRouting($install->routing, new ModelRouting()))->now();
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
     * @param class-string $class
     *
     * @return list<string>
     */
    private function publicFields(string $class): array
    {
        return array_map(static fn (ReflectionProperty $property): string => $property->getName(), new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC));
    }
}
