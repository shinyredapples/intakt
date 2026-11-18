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
use PHPUnit\Framework\Attributes\UsesClass;

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
        $this->noticesAre($this->notice('main', '2026-11-02 08:00:00 UTC', '2026-11-02 11:00:00 UTC'));

        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        $item = $page->filter('.wait-item');
        self::assertCount(1, $item);
        self::assertSame('No usage of account claude_code.main has been read for hours', $item->filter('h3')->text());
        self::assertSame('All projects', $item->filter('.meta span')->first()->text());
        self::assertStringContainsString('Waiting', $item->filter('.meta')->text());
        self::assertStringContainsString('only one call at a time', $item->filter('.conseq')->text());
        self::assertSame('bin/console intakt:vendor:probe', $item->filter('.act .copy code')->text());
        self::assertSame('Recommended', $item->filter('.act li')->first()->filter('.recommended')->text());
        self::assertNotNull($item->filter('details.why')->attr('open'));
        self::assertSame('Why', $item->filter('details.why summary')->text());
    }

    public function testTheOldestNoticeComesFirst(): void
    {
        $this->noticesAre(
            $this->notice('first', '2026-11-01 08:00:00 UTC', '2026-11-01 11:00:00 UTC'),
            $this->notice('second', '2026-11-02 08:00:00 UTC', '2026-11-02 11:00:00 UTC'),
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
        $this->noticesAre($this->notice('main', '2026-11-02 08:00:00 UTC', '2026-11-02 11:00:00 UTC'));

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

    public function testAPhoneOpeningTheAddressFromOutsideLandsOnWaitingOnYou(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_SEC_CH_UA_MOBILE' => '?1']);

        self::assertResponseRedirects('/waiting', 303);
    }

    public function testAPhoneClickingBoardInsideTheDashboardStillReachesTheBoard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_SEC_CH_UA_MOBILE' => '?1', 'HTTP_REFERER' => 'http://localhost:7780/waiting']);

        self::assertResponseIsSuccessful();
    }

    public function testADesktopOpeningTheAddressStillGetsTheBoard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_SEC_CH_UA_MOBILE' => '?0']);

        self::assertResponseIsSuccessful();
    }

    private function noticesAre(OutageNotice ...$notices): void
    {
        $stub = self::createStub(OwnerNotices::class);
        $stub->method('oldestFirst')->willReturn(array_values($notices));
        self::getContainer()->set(OwnerNotices::class, $stub);
    }

    private function notice(string $account, string $lastReading, string $noticed): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, $account), new DateTimeImmutable($lastReading), new DateTimeImmutable($noticed));
    }
}
