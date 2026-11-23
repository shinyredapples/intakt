<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Dashboard\Domain\TeamAccount;
use Intakt\Core\Dashboard\Domain\TeamAccountState;
use Intakt\Core\Dashboard\Domain\TeamRole;
use Intakt\Core\Dashboard\Domain\TeamWindow;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ShowTeam\ShowTeamController;
use Intakt\Core\Review\Contract\ReviewerRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTeamController::class)]
#[UsesClass(TeamAccount::class)]
#[UsesClass(TeamAccountState::class)]
#[UsesClass(TeamRole::class)]
#[UsesClass(TeamWindow::class)]
#[UsesClass(RefuseForeignHost::class)]
final class ShowTeamControllerTest extends DashboardPageTestCase
{
    public function testTheTeamPageListsEachAccountOnceWithItsNameAndKind(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work_subscription'), new Account(Provider::Anthropic, 'overflow_key')]);

        $page = $this->browser->request('GET', '/team');

        self::assertSame(['Overflow key', 'Work subscription'], $page->filter('.acct .acct-name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(['API key, pay per use, Anthropic', 'Subscription, Claude Code'], $page->filter('.acct .acct-name + p.small')->each(static fn (Crawler $kind): string => $kind->text()));
    }

    public function testASubscriptionAccountShowsUsedFromWhatTheBudgetRecorded(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => 46.0, UsageWindow::Weekly->value => 61.4]);

        $windows = $this->browser->request('GET', '/team')->filter('.acct .window');

        self::assertSame(['Five hour window46% used', 'Weekly window61% used'], $windows->each(static fn (Crawler $window): string => $window->filter('.wl')->text()));
        self::assertStringContainsString('Used 46%', $windows->first()->filter('.bar')->attr('aria-label') ?? '');
    }

    public function testAWindowWithoutAReadingSaysSoInsteadOfZero(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::Weekly->value => 10.0]);

        $windows = $this->browser->request('GET', '/team')->filter('.acct .window .wl');

        self::assertSame('Five hour windowNo reading yet', $windows->first()->text());
        self::assertStringNotContainsString('0%', $windows->first()->text());
    }

    public function testAPayPerUseAccountHasNoWindow(): void
    {
        $this->serve([new Account(Provider::Anthropic, 'default')]);

        $page = $this->browser->request('GET', '/team');

        self::assertCount(0, $page->filter('.acct .window'));
        self::assertSame('Pay per use: no usage window.', $page->filter('.acct p.muted')->last()->text());
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
        $this->serve([new Account(Provider::ClaudeCode, 'work'), new Account(Provider::Anthropic, 'overflow')], [], [], ['correctness' => [Provider::ClaudeCode, Provider::Anthropic], 'security' => [Provider::Anthropic]]);

        $roles = $this->browser->request('GET', '/team')->filter('.side .role');

        self::assertSame(
            [['correctness', 'Overflow|Work'], ['security', 'Overflow']],
            $roles->each(static fn (Crawler $role): array => [$role->filter('h3')->text(), implode('|', $role->filter('.meta span')->each(static fn (Crawler $name): string => $name->text()))]),
        );
    }

    public function testThePageShowsNoResetTimeAndNoCeiling(): void
    {
        $this->serve([new Account(Provider::ClaudeCode, 'work')], [UsageWindow::FiveHour->value => 46.0]);

        $text = $this->browser->request('GET', '/team')->filter('main')->text();

        self::assertStringNotContainsStringIgnoringCase('resets', $text);
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

    /**
     * @param list<Account> $accounts
     * @param array<string, float> $used
     * @param array<string, string> $states
     * @param array<string, list<Provider>> $roles
     */
    private function serve(array $accounts, array $used = [], array $states = [], array $roles = []): void
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
        $use->method('of')->willReturnCallback(static fn (Account $account, UsageWindow $window): ?WindowUse => isset($used[$window->value])
            ? new WindowUse(new Usage($used[$window->value], null), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), 60)
            : null);
        $container->set(MeasuredUse::class, $use);

        $served = self::createStub(ReviewerRoles::class);
        $served->method('servedBy')->willReturnCallback(static fn (Provider $provider): array => array_keys(array_filter($roles, static fn (array $providers): bool => in_array($provider, $providers, true))));
        $container->set(ReviewerRoles::class, $served);
    }
}
