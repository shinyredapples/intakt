<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Dashboard\UseCase\ShowTeam\AccountState;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRole;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(TeamRoster::class)]
#[CoversClass(Team::class)]
#[CoversClass(TeamAccount::class)]
#[CoversClass(TeamRole::class)]
#[UsesClass(Account::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Paths::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ProjectNames::class)]
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

        self::assertSame(['account', 'state', 'roles', 'accounts'], $fields);
    }

    public function testTheDeveloperRoleListsTheProviderOfTheDefaultTier(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->project('alpha', Provider::ClaudeCode));

        self::assertSame(['Developer'], $this->roleNames($team->accounts[0]));
    }

    public function testASensitivePathRoutedToAnotherVendorMakesThatVendorADeveloperToo(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->accounts(Provider::Anthropic, 'default')->project('alpha', Provider::ClaudeCode, Provider::Anthropic));

        self::assertSame(['Developer'], $this->roleNames($team->accounts[0]));
        self::assertSame(['Developer'], $this->roleNames($team->accounts[1]));
    }

    public function testARoutedProviderTheBuildRuntimeCannotRunIsMarked(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->accounts(Provider::Anthropic, 'default')->project('alpha', Provider::ClaudeCode, Provider::Anthropic));

        self::assertSame('Developer (the build runtime cannot run it)', $team->accounts[0]->roles[0]->inWords());
        self::assertSame('Developer', $team->accounts[1]->roles[0]->inWords());
    }

    public function testEveryReviewerOfThePanelIsAReviewerRoleOfItsProvider(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::OpenAi, 'default')->accounts(Provider::Anthropic, 'default')->project('alpha', Provider::ClaudeCode)->reviewer('alpha', 'correctness', Provider::OpenAi)->reviewer('alpha', 'style', Provider::Anthropic));

        self::assertSame(['Reviewer: correctness'], $this->roleNames($team->accounts[1]));
        self::assertSame(['Reviewer: style'], $this->roleNames($team->accounts[0]));
    }

    public function testAnAccountServesEachRoleOnceAlsoWhenTwoProjectsRouteToIt(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->project('alpha', Provider::ClaudeCode)->project('beta', Provider::ClaudeCode)->reviewer('alpha', 'style', Provider::ClaudeCode)->reviewer('beta', 'style', Provider::ClaudeCode));

        self::assertSame(['Developer', 'Reviewer: style'], $this->roleNames($team->accounts[0]));
    }

    public function testAnAccountWithoutItsKeyStillShowsItsRoles(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->withoutKey('claude_code.default')->project('alpha', Provider::ClaudeCode));

        self::assertSame(AccountState::NoKey, $team->accounts[0]->state);
        self::assertSame(['Developer'], $this->roleNames($team->accounts[0]));
    }

    public function testEveryAccountOfAProviderServesTheProvidersRoles(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default', 'team')->project('alpha', Provider::ClaudeCode));

        self::assertSame(['Developer'], $this->roleNames($team->accounts[1]));
    }

    public function testAProviderNoRoleUsesServesNone(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::Codex, 'default')->project('alpha', Provider::ClaudeCode));

        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testAProjectWithoutRoutingOrPanelAddsNoRoleAndDoesNotStopTheRoster(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->project('alpha', Provider::ClaudeCode)->unrouted('alpha'));

        self::assertSame([], $team->accounts[0]->roles);
    }

    public function testAProjectWhoseTierCannotBeResolvedAddsNoRole(): void
    {
        $team = $this->teamOf(new GivenInstall()->accounts(Provider::ClaudeCode, 'default')->project('alpha', Provider::ClaudeCode)->unresolvable('alpha'));

        self::assertSame([], $team->accounts[0]->roles);
    }

    private function routing(GivenInstall $install): BuildRouting
    {
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturnCallback(static fn (string $project, BuildFacts $facts): TierChoice => in_array($project, $install->unrouted, true)
            ? throw new BuildRoutingNotConfigured($project) : (EscalationOutcome::Confirmed === $facts->escalation ? new TierChoice(Tier::Strong, TierReason::ConfirmedEscalation) : new TierChoice(Tier::Standard, TierReason::Default)));

        return $routing;
    }

    private function tiers(GivenInstall $install): TierResolver
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames(...array_keys($install->developers)));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => in_array($project, $install->unresolvable, true)
            ? throw new TierNotMapped($project, $tier) : new ResolvedModel($install->developers[$project][Tier::Strong === $tier ? 'strong' : 'default'], 'model'));

        return $tiers;
    }

    private function panels(GivenInstall $install): Panels
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturnCallback(static function (string $project, ChangeDescription $change) use ($install): Panel {
            if (!$change->wantsFullReview() || !isset($install->reviewers[$project])) {
                throw new PanelNotConfigured($project);
            }

            return new Panel(array_values(array_map(static fn (string $role, Provider $provider): PanelReviewer => new PanelReviewer(new ReviewerRole($role, 'Prompt.'), new ReviewerModel(Tier::Standard, $provider), 1), array_keys($install->reviewers[$project]), $install->reviewers[$project])), QuorumPolicy::Majority);
        });

        return $panels;
    }

    /**
     * @return list<string>
     */
    private function roleNames(TeamAccount $account): array
    {
        return array_map(static fn (TeamRole $role): string => $role->name, $account->roles);
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

        return new TeamRoster($listed, $credentials, $cooldowns, $this->routing($install), $this->tiers($install), $this->panels($install))->now();
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
