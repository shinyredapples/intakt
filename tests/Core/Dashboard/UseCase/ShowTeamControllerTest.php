<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Dashboard\Domain\Ago;
use Intakt\Core\Dashboard\Domain\TeamAccount;
use Intakt\Core\Dashboard\Domain\TeamAccountState;
use Intakt\Core\Dashboard\Domain\TeamRole;
use Intakt\Core\Dashboard\Domain\TeamSpend;
use Intakt\Core\Dashboard\Domain\TeamWindow;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ShowTeam\LedgerAccountSpend;
use Intakt\Core\Dashboard\UseCase\ShowTeam\ShowTeamController;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Review\Contract\ReviewerRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTeamController::class)]
#[CoversClass(LedgerAccountSpend::class)]
#[CoversClass(TeamRoster::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(Ago::class)]
#[UsesClass(TeamAccount::class)]
#[UsesClass(TeamAccountState::class)]
#[UsesClass(TeamRole::class)]
#[UsesClass(TeamSpend::class)]
#[UsesClass(TeamWindow::class)]
#[UsesClass(RefuseForeignHost::class)]
final class ShowTeamControllerTest extends DashboardPageTestCase
{
    /** @var array<string, list<Provider>> */
    private array $roles = [];

    /** @var array<string, Provider> */
    private array $builds = [];

    public function testTheTeamPageListsEachAccountOnceWithItsNameAndKind(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work_subscription'), new Account(Provider::Anthropic, 'overflow_key')]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame(['Overflow key', 'Work subscription'], $page->filter('.acct .acct-name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(['API key, pay per use, Anthropic', 'Subscription, Claude Code'], $page->filter('.acct .acct-name + p.small')->each(static fn (Crawler $kind): string => $kind->text()));
    }

    public function testASubscriptionAccountShowsUsedAndTheAgeOfItsReading(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => $this->reading(46.0, 60), UsageWindow::Weekly->value => $this->reading(61.4, 7200)]);

        $windows = $this->browser->request('GET', '/team')->filter('.acct .window');

        self::assertSame(['Five-hour window46% used, read 1 min ago', 'Weekly window61% used, read 2 h ago'], $windows->each(static fn (Crawler $window): string => $window->filter('.wl')->text()));
    }

    public function testTheBarNamesOnlyTheUsedShareBecauseReservedAndForecastHaveNoSource(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => $this->reading(46.0, 60)]);

        $bar = $this->browser->request('GET', '/team')->filter('.acct .window .bar')->first();

        self::assertSame('Used 46%', $bar->attr('aria-label'));
        self::assertCount(0, $this->browser->getCrawler()->filter('.acct .legend'));
    }

    public function testAWindowWithoutAReadingSaysSoAndDrawsNoBar(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::Weekly->value => $this->reading(10.0, 60)]);

        $window = $this->browser->request('GET', '/team')->filter('.acct .window')->first();

        self::assertSame('Five-hour windowNo reading yet', $window->filter('.wl')->text());
        self::assertCount(0, $window->filter('.bar'));
        self::assertStringNotContainsString('Used 0%', (string) $this->browser->getResponse()->getContent());
    }

    public function testAReadingFromBeforeTheWindowResetShowsAsLastKnownWithItsAge(): void
    {
        $taken = new DateTimeImmutable('2026-10-20T12:00:00+00:00');
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::Weekly->value => new WindowUse(new Usage(100.0, new DateTimeImmutable('2026-10-21T12:00:00+00:00')), $taken, 691200)]);

        $window = $this->browser->request('GET', '/team')->filter('.acct .window')->last();

        self::assertSame('Weekly windowLast known 100% used, read 8 d ago', $window->filter('.wl')->text());
        self::assertSame('bar last-known', $window->filter('.bar')->attr('class'));
        self::assertSame('Last known: used 100%', $window->filter('.bar')->attr('aria-label'));
    }

    public function testAReadingWhoseResetHasPassedIsLastKnownEvenWithinTheWindowLength(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => new WindowUse(new Usage(80.0, new DateTimeImmutable('2026-10-28T10:00:00+00:00')), new DateTimeImmutable('2026-10-28T09:00:00+00:00'), 10800)]);

        $note = $this->browser->request('GET', '/team')->filter('.acct .window .wl')->first()->text();

        self::assertSame('Five-hour windowLast known 80% used, read 3 h ago', $note);
    }

    public function testAPayPerUseAccountShowsItsApiSpendOfTodayAndThisWeek(): void
    {
        $this->ledger->spendOn('anthropic.overflow', 1_000_000, new DateTimeImmutable('2026-10-28T09:00:00+00:00'));
        $this->ledger->spendOn('anthropic.overflow', 500_000, new DateTimeImmutable('2026-10-26T00:00:00+00:00'));
        $this->ledger->spendOn('anthropic.overflow', 9_000_000, new DateTimeImmutable('2026-10-25T23:59:59+00:00'));
        $this->ledger->spendOn('anthropic.other', 9_000_000, new DateTimeImmutable('2026-10-28T09:00:00+00:00'));
        $this->serve([new Account(Provider::Anthropic, 'overflow')]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame(['API spend today' => '2.0000 USD', 'API spend this week' => '3.0000 USD'], $this->spendOf($page));
        self::assertCount(0, $page->filter('.acct .window'));
        self::assertSame('Pay per use: no usage window. Spend is estimated from the recorded usage, prices of 2026-11-13.', $page->filter('.acct .spend + p')->text());
    }

    public function testASpendWithoutAPriceReadsAsUnknownNeverAsZero(): void
    {
        $this->ledger->spendOn('openai.default', 1_000, new DateTimeImmutable('2026-10-28T09:00:00+00:00'), 'model-z');
        $this->serve([new Account(Provider::OpenAi, 'default')]);

        $spend = $this->spendOf($this->browser->request('GET', '/team'));

        self::assertSame(['API spend today' => 'unknown, no price for model-z', 'API spend this week' => 'unknown, no price for model-z'], $spend);
    }

    public function testASubscriptionAccountShowsNoApiSpend(): void
    {
        $this->ledger->spendOn('claude_code.work', 1_000_000, new DateTimeImmutable('2026-10-28T09:00:00+00:00'));
        $this->serve([new Account(Provider::ClaudeCode, 'work')]);

        self::assertCount(0, $this->browser->request('GET', '/team')->filter('.acct .spend'));
    }

    public function testAnAccountShowsItsState(): void
    {
        $this->serve(
            [new Account(Provider::Codex, 'one'), new Account(Provider::Codex, 'two'), new Account(Provider::Codex, 'three')],
            [],
            ['codex.two' => 'cooling', 'codex.three' => 'rejected'],
        );

        $chips = $this->browser->request('GET', '/team')->filter('.acct .chip');

        self::assertSame(['Working', 'Cooling down', 'Key rejected'], $chips->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function testEachRoleListsTheAccountsOfTheProvidersItUses(): void
    {
        $this->roles = ['correctness' => [Provider::ClaudeCode, Provider::Anthropic], 'security' => [Provider::Anthropic]];
        $this->serve([new Account(Provider::ClaudeCode, 'work'), new Account(Provider::Anthropic, 'overflow')]);

        self::assertSame(['correctness' => 'Overflow (Anthropic)|Work (Claude Code)', 'security' => 'Overflow (Anthropic)'], $this->rolesOf($this->browser->request('GET', '/team')));
    }

    public function testAccountsOfTheSameNameStayApartInARole(): void
    {
        $this->roles = ['correctness' => [Provider::Anthropic, Provider::OpenAi]];
        $this->serve([new Account(Provider::Anthropic, 'default'), new Account(Provider::OpenAi, 'default')]);

        self::assertSame(['correctness' => 'Default (Anthropic)|Default (OpenAI)'], $this->rolesOf($this->browser->request('GET', '/team')));
    }

    public function testARoleWhoseAccountsAreAllDisabledStillShows(): void
    {
        $this->roles = ['correctness' => [Provider::Codex]];
        $this->serve([]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame(['correctness' => 'No enabled account'], $this->rolesOf($page));
        self::assertCount(0, $page->filter('.side p.none'));
    }

    public function testTheDeveloperServesTheProvidersOfTheBuildAndEscalationTiers(): void
    {
        $this->builds = [Tier::Standard->value => Provider::Codex, Tier::Strong->value => Provider::ClaudeCode];
        $this->serve([new Account(Provider::ClaudeCode, 'work'), new Account(Provider::Codex, 'home'), new Account(Provider::Anthropic, 'overflow')]);

        self::assertSame(['Developer' => 'Work (Claude Code)|Home (Codex)'], $this->rolesOf($this->browser->request('GET', '/team')));
    }

    public function testOneAccountServesEveryRoleOfItsProvider(): void
    {
        $this->roles = ['correctness' => [Provider::ClaudeCode], 'security' => [Provider::ClaudeCode]];
        $this->serve([new Account(Provider::ClaudeCode, 'work')]);

        self::assertSame(['correctness' => 'Work (Claude Code)', 'security' => 'Work (Claude Code)'], $this->rolesOf($this->browser->request('GET', '/team')));
    }

    public function testARoleNameWithMarkupShowsAsText(): void
    {
        $this->roles = ['<b>loud</b>' => [Provider::ClaudeCode]];
        $this->serve([new Account(Provider::ClaudeCode, 'work')]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame('<b>loud</b>', $page->filter('.side .role h3')->text());
        self::assertCount(0, $page->filter('.side .role h3 b'));
    }

    public function testThePageShowsNoResetTimeAndNoCeiling(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => new WindowUse(new Usage(46.0, new DateTimeImmutable('2026-10-28T14:00:00+00:00')), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), 60)]);

        $text = $this->browser->request('GET', '/team')->filter('main')->text();

        self::assertStringNotContainsStringIgnoringCase('resets', $text);
        self::assertStringNotContainsString('14:00', $text);
        self::assertStringNotContainsString('limit', $text);
    }

    public function testTheRailMarksTeamAsCurrentAndKeepsItUnderMore(): void
    {
        $this->serve([]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame('team', $page->filter('.rail a[aria-current="page"]')->attr('data-k'));
        self::assertCount(1, $page->filter('.rail a.in-more[data-k="team"]'));
    }

    public function testWithoutAccountsAndRolesThePageSaysSo(): void
    {
        $this->serve([]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame('No account is enabled yet.', $page->filter('#acc-h + p.none')->text());
        self::assertSame('No role is configured.', $page->filter('.side p.none')->text());
    }

    private function reading(float $used, int $age): WindowUse
    {
        return new WindowUse(new Usage($used, null), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), $age);
    }

    /**
     * @return array<string, string>
     */
    private function spendOf(Crawler $page): array
    {
        $tiles = $page->filter('.acct .spend .tile');

        return array_combine($tiles->each(static fn (Crawler $tile): string => $tile->filter('dt')->text()), $tiles->each(static fn (Crawler $tile): string => $tile->filter('dd')->text()));
    }

    /**
     * @return array<string, string>
     */
    private function rolesOf(Crawler $page): array
    {
        $roles = $page->filter('.side .role');

        return array_combine(
            $roles->each(static fn (Crawler $role): string => $role->filter('h3')->text()),
            $roles->each(static fn (Crawler $role): string => implode('|', $role->filter('.meta span')->each(static fn (Crawler $name): string => $name->text()))),
        );
    }

    /**
     * @param list<Account>            $accounts
     * @param array<string, WindowUse> $used
     * @param array<string, string>    $states
     */
    private function serve(array $accounts, array $used = [], array $states = []): void
    {
        $container = self::getContainer();
        $enabled = self::createStub(EnabledAccounts::class);
        $enabled->method('of')->willReturnCallback(static fn (Provider $provider): array => array_values(array_filter($accounts, static fn (Account $account): bool => $account->provider === $provider)));
        $container->set(EnabledAccounts::class, $enabled);

        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isRejected')->willReturnCallback(static fn (Account $account): bool => 'rejected' === ($states[$account->key()] ?? null));
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => 'cooling' === ($states[$account->key()] ?? null));
        $container->set(AccountCooldowns::class, $cooldowns);

        $use = self::createStub(MeasuredUse::class);
        $use->method('of')->willReturnCallback(static fn (Account $account, UsageWindow $window): ?WindowUse => $used[$window->value] ?? null);
        $container->set(MeasuredUse::class, $use);

        $container->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->serveRoles();
    }

    private function serveRoles(): void
    {
        $container = self::getContainer();
        $served = self::createStub(ReviewerRoles::class);
        $roles = $this->roles;
        $served->method('servedBy')->willReturnCallback(static fn (Provider $provider): array => array_keys(array_filter($roles, static fn (array $providers): bool => in_array($provider, $providers, true))));
        $container->set(ReviewerRoles::class, $served);

        $builds = $this->builds;
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturnCallback(static fn (string $project, BuildFacts $facts): TierChoice => $facts->setback instanceof Setback
            ? new TierChoice(Tier::Strong, TierReason::Setback)
            : new TierChoice(Tier::Standard, TierReason::Default));
        $container->set(BuildRouting::class, $routing);

        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn([] === $builds ? [] : ['alpha']);
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel($builds[$tier->value] ?? Provider::Anthropic, 'model-a'));
        $container->set(TierResolver::class, $tiers);
    }
}
