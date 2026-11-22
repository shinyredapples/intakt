<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListWaiting\ListWaitingController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListWaitingController::class)]
#[UsesClass(WaitingItem::class)]
#[UsesClass(Character::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(Account::class)]
#[UsesClass(FilterProjects::class)]
#[UsesClass(LedgerReviews::class)]
final class ListWaitingControllerTest extends DashboardPageTestCase
{
    public function testTheWaitingPageListsEachOwnerNoticeWithItsRoleLeadMetaConsequenceAndOneTap(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        $item = $page->filter('.wait-item');
        self::assertCount(1, $item);
        self::assertStringContainsString('images/roles/maintainer-light-96', (string) $item->filter('img.character-light')->attr('src'));
        self::assertSame('No usage of account claude_code.main has been read since 2026-11-02 08:00 UTC', $item->filter('h2.wait-lead')->text());
        self::assertSame('Maintainer', $item->filter('.meta span')->first()->text());
        self::assertSame('', (string) $item->filter('img.character-light')->attr('alt'));
        self::assertStringContainsString('All projects', $item->filter('.meta')->text());
        self::assertStringContainsString('Waiting', $item->filter('.meta')->text());
        self::assertStringContainsString('at most one call at a time', $item->filter('.conseq')->text());
        self::assertSame('Open the provider\'s status page', $item->filter('a.wait-act.primary b')->text());
        self::assertSame('Shows whether the provider reports trouble. This card leaves when readings return.', $item->filter('a.wait-act.primary small')->text());
    }

    // No disclosure and no command to copy, because an overview page leads to one tap and details live on their own page (UX2).
    public function testAWaitingCardOffersNoCopyButtonNoCommandAndNoWhyDisclosure(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertCount(0, $page->filter('main details, main summary'));
        self::assertStringContainsString('Maintainer', $page->filter('.wait-item')->text());
        self::assertCount(0, $page->filter('main [data-copy], main code'));
        self::assertStringNotContainsString('bin/console', $page->filter('main')->text());
    }

    // Once per page, because the octopus is the conductor and the cards show the role that waits (UX2).
    public function testTheWaitingOctopusAppearsOnceAboveTheCountOfThingsThatNeedYou(): void
    {
        $this->noticesAre(
            $this->notice('first', new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')),
            $this->notice('second', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')),
        );

        $page = $this->browser->request('GET', '/waiting');

        self::assertCount(1, $page->filter('main img.character-light[src*="octopus-"]'));
        self::assertCount(1, $page->filter('.wait-hero img.character-light[src*="octopus-waiting"]'));
        self::assertSame('2 things need you', $page->filter('.wait-hero h1')->text());
        self::assertSame('Waiting on you', $page->filter('.wait-hero .wait-kicker')->text());
    }

    // The page has only its h1 above the items, so a lower level would skip one in the outline.
    public function testEachItemHeadingIsAnH2UnderThePageTitle(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertCount(1, $page->filter('main h1'));
        self::assertCount(1, $page->filter('.wait-item > h2'));
        self::assertCount(0, $page->filter('.wait-item h3'));
    }

    // The link opens in the same tab, so it leaves no opener to guard.
    public function testTheStatusPageLinkOpensInTheSameTabWithoutRel(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertNull($page->filter('.wait-item a[href]')->attr('rel'));
        self::assertNull($page->filter('.wait-item a[href]')->attr('target'));
    }

    public function testTheOldestNoticeComesFirst(): void
    {
        $this->noticesAre(
            $this->notice('first', new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')),
            $this->notice('second', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')),
        );

        $page = $this->browser->request('GET', '/waiting');

        self::assertStringContainsString('claude_code.first', $page->filter('.wait-item h2')->first()->text());
        self::assertStringContainsString('claude_code.second', $page->filter('.wait-item h2')->last()->text());
    }

    public function testTheWaitingPageSaysNothingNeedsYouWhenNoNoticeWaits(): void
    {
        $this->noticesAre();

        $page = $this->browser->request('GET', '/waiting');

        self::assertSame('Nothing needs you', $page->filter('.empty h2')->text());
        self::assertCount(0, $page->filter('.wait-item'));
    }

    public function testAnUnknownProjectInTheQueryLeavesTheFilterOnEveryProject(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting?project=missing');

        self::assertCount(0, $page->filter('[data-project-trail]'));
        self::assertCount(1, $page->filter('.wait-item'));
        self::assertCount(0, $page->filter('.wait-item[data-project]'));
    }

    public function testTheRailEntryOfWaitingOnYouIsCurrentOnThePage(): void
    {
        $this->noticesAre();

        $page = $this->browser->request('GET', '/waiting');

        self::assertSame('/waiting', $page->filter('.rail a.nav[data-k="waiting"]')->attr('href'));
        self::assertSame('page', $page->filter('.rail a.nav[data-k="waiting"]')->attr('aria-current'));
    }

    public function testAKnownProjectFilterLeavesWaitingOnYouTheOnlyCurrentRailEntry(): void
    {
        $this->noticesAre();
        $this->ledger->startRound('round-1', 'acme');

        $page = $this->browser->request('GET', '/waiting?project=acme');

        self::assertCount(1, $page->filter('[data-project-trail]'));
        self::assertSame(['waiting'], $page->filter('.rail a.nav[aria-current]')->each(static fn (Crawler $entry): ?string => $entry->attr('data-k')));
    }

    #[DataProvider('statusPages')]
    public function testTheOutageCardLinksToTheStatusPageOfItsProvider(Provider $provider, string $expected): void
    {
        $this->noticesAre(new OutageNotice(new Account($provider, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertSame([$expected], $page->filter('.wait-item a[href]')->each(static fn (Crawler $link): ?string => $link->attr('href')));
    }

    /**
     * @return iterable<string, array{Provider, string}>
     */
    public static function statusPages(): iterable
    {
        yield 'Anthropic' => [Provider::Anthropic, 'https://status.anthropic.com'];
        yield 'Claude Code' => [Provider::ClaudeCode, 'https://status.anthropic.com'];
        yield 'OpenAI' => [Provider::OpenAi, 'https://status.openai.com'];
        yield 'Codex' => [Provider::Codex, 'https://status.openai.com'];
        yield 'an OpenAI-compatible server, which has no status page, so the board' => [Provider::OpenAiCompatible, '/?board=1'];
    }

    private function noticesAre(OutageNotice ...$notices): void
    {
        $stub = self::createStub(OwnerNotices::class);
        $stub->method('stillRunningOldestFirst')->willReturn(array_values($notices));
        self::getContainer()->set(OwnerNotices::class, $stub);
    }

    private function notice(string $account, DateTimeImmutable $lastReading, DateTimeImmutable $noticed): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, $account), $lastReading, $noticed);
    }
}
