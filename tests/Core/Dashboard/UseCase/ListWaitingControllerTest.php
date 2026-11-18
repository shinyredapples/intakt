<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Domain\WaitingChoice;
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
#[UsesClass(WaitingChoice::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(Account::class)]
#[UsesClass(FilterProjects::class)]
#[UsesClass(LedgerReviews::class)]
final class ListWaitingControllerTest extends DashboardPageTestCase
{
    public function testTheWaitingPageListsEachOwnerNoticeWithItsLeadMetaConsequenceActionAndWhy(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        $item = $page->filter('.wait-item');
        self::assertCount(1, $item);
        self::assertSame('No usage of account claude_code.main has been read since 2026-11-02 08:00 UTC', $item->filter('h3')->text());
        self::assertSame('All projects', $item->filter('.meta span')->first()->text());
        self::assertStringContainsString('Waiting', $item->filter('.meta')->text());
        self::assertStringContainsString('only one call at a time', $item->filter('.conseq')->text());
        self::assertSame('php bin/console intakt:vendor:probe', $item->filter('.act .copy code')->text());
        self::assertSame('Recommended', $item->filter('.act li')->first()->filter('.recommended')->text());
        self::assertNotNull($item->filter('details.why')->attr('open'));
        self::assertSame('Why', $item->filter('details.why summary')->text());
    }

    // Turbo runs a body script again on every visit, so a binding in the body would leave the document's listeners twice.
    public function testTheCopyScriptIsBoundFromTheHeadAndNeverFromTheBodyAVisitReplaces(): void
    {
        $this->noticesAre($this->notice('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        $page = $this->browser->request('GET', '/waiting');

        self::assertStringContainsString('copyCommands(document, navigator.clipboard ?? {}, matchMedia, getSelection());', $page->filter('head script[type="module"]')->text());
        self::assertCount(0, $page->filter('body script[type="module"]'));
        self::assertSame('php bin/console intakt:vendor:probe', $page->filter('.wait-item button[data-copy]')->attr('data-copy'));
    }

    public function testTheOldestNoticeComesFirst(): void
    {
        $this->noticesAre(
            $this->notice('first', new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')),
            $this->notice('second', new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')),
        );

        $page = $this->browser->request('GET', '/waiting');

        self::assertStringContainsString('claude_code.first', $page->filter('.wait-item h3')->first()->text());
        self::assertStringContainsString('claude_code.second', $page->filter('.wait-item h3')->last()->text());
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
        yield 'an OpenAI-compatible server, which has no status page, so the board with its flag' => [Provider::OpenAiCompatible, '/?board=1'];
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
