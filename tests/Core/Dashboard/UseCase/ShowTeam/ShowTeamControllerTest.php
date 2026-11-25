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
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Dashboard\UseCase\ShowTeam\AccountState;
use Intakt\Core\Dashboard\UseCase\ShowTeam\ShowTeamController;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRole;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTeamController::class)]
#[CoversClass(Team::class)]
#[CoversClass(AccountState::class)]
#[UsesClass(TeamRoster::class)]
#[UsesClass(TeamAccount::class)]
#[UsesClass(TeamRole::class)]
#[UsesClass(Account::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ProjectNames::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(Paths::class)]
final class ShowTeamControllerTest extends DashboardPageTestCase
{
    public function testTheTeamPageListsEveryAccountOnceWithItsKindStateAndRoles(): void
    {
        $this->givenAccounts([Provider::ClaudeCode->value => ['default'], Provider::Anthropic->value => ['overflow']]);

        $page = $this->browser->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['overflow', 'default'], $page->filter('.acct .acct-name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(['API key, anthropic', 'Subscription, claude_code'], $page->filter('.acct .acct-kind')->each(static fn (Crawler $kind): string => $kind->text()));
        self::assertSame(['Ready', 'Ready'], $page->filter('.acct .chip')->each(static fn (Crawler $state): string => $state->text()));
        self::assertSame(
            ['Serves: Developer (the build runtime cannot run it)', 'Serves: Developer'],
            $page->filter('.acct .acct-roles')->each(static fn (Crawler $roles): string => $roles->text()),
        );
    }

    public function testTheRolesBesideTheAccountsNameTheAccountsEachRoleRunsOn(): void
    {
        $this->givenAccounts([Provider::ClaudeCode->value => ['default', 'home'], Provider::Anthropic->value => ['overflow']]);

        $roles = $this->browser->request('GET', '/team')->filter('.side .role');

        self::assertSame(['Developer'], $roles->filter('h3')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(
            ['anthropic.overflow (the build runtime cannot run it)', 'claude_code.default', 'claude_code.home'],
            $roles->filter('.meta span')->each(static fn (Crawler $account): string => $account->text()),
        );
    }

    public function testAnAccountNoRoleUsesSaysItServesNoRole(): void
    {
        $this->givenAccounts([Provider::Codex->value => ['default']]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame('Serves no role.', $page->filter('.acct .acct-roles')->text());
        self::assertSame('No account serves a role yet.', $page->filter('.side .none')->text());
    }

    public function testAnInstallWithoutAccountsSaysSo(): void
    {
        $this->givenAccounts([]);

        $page = $this->browser->request('GET', '/team');

        self::assertCount(0, $page->filter('.acct'));
        self::assertSame('No account is listed.', $page->filter('#accounts-h + .none')->text());
    }

    public function testEachStateShowsInWordsWithItsDot(): void
    {
        $this->givenAccounts(
            [Provider::Codex->value => ['ready', 'cooling', 'rejected', 'nokey']],
            withoutKey: 'codex.nokey',
            coolingDown: 'codex.cooling',
            rejected: 'codex.rejected',
        );

        $chips = $this->browser->request('GET', '/team')->filter('.acct .chip');

        self::assertSame(['Ready', 'Cooling down', 'Key rejected', 'No key set'], $chips->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame(['dot ok', 'dot idle', 'dot broken', 'dot idle'], $chips->filter('.dot')->each(static fn (Crawler $dot): string => (string) $dot->attr('class')));
    }

    public function testTheTeamPageShowsNoResetTimeCeilingOrWindow(): void
    {
        $this->givenAccounts([Provider::ClaudeCode->value => ['default']]);

        $page = $this->browser->request('GET', '/team');

        self::assertCount(0, $page->filter('.window, .bar, .reset, time'));
        self::assertStringNotContainsStringIgnoringCase('resets', $page->filter('main')->text());
        self::assertStringNotContainsStringIgnoringCase('ceiling', $page->filter('main')->text());
    }

    public function testTheTeamPageMarksTeamAsTheCurrentRailEntry(): void
    {
        $this->givenAccounts([]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame(['team', 'more'], $page->filter('.rail .nav[aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
        self::assertSame('Team', trim($page->filter('#more a[aria-current="page"]')->text()));
    }

    /**
     * @param array<string, list<string>> $accounts
     */
    private function givenAccounts(array $accounts, string $withoutKey = '', string $coolingDown = '', string $rejected = ''): void
    {
        $listed = self::createStub(ListedAccounts::class);
        $listed->method('of')->willReturnCallback(static fn (Provider $provider): array => array_map(static fn (string $name): Account => new Account($provider, $name), $accounts[$provider->value] ?? []));
        $credentials = self::createStub(AccountCredentials::class);
        $credentials->method('areSet')->willReturnCallback(static fn (Account $account): bool => $account->key() !== $withoutKey);
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isRejected')->willReturnCallback(static fn (Account $account): bool => $account->key() === $rejected);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => in_array($account->key(), [$coolingDown, $rejected], true));
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturnCallback(static fn (string $project, BuildFacts $facts): TierChoice => EscalationOutcome::Confirmed === $facts->escalation ? new TierChoice(Tier::Strong, TierReason::ConfirmedEscalation) : new TierChoice(Tier::Standard, TierReason::Default));
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames('alpha'));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Tier::Strong === $tier ? Provider::Anthropic : Provider::ClaudeCode, 'model'));
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured('alpha'));

        self::getContainer()->set(TeamRoster::class, new TeamRoster($listed, $credentials, $cooldowns, $routing, $tiers, $panels));
    }
}
