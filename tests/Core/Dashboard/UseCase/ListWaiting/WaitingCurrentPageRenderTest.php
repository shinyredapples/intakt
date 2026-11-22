<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ListWaiting;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Intakt\Core\Dashboard\UseCase\ListWaiting\ListWaitingController;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListWaitingController::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
final class WaitingCurrentPageRenderTest extends DashboardPageTestCase
{
    public function testOnlyWaitingIsMarkedAsTheCurrentPage(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('waitingAndEndedOnTheDayOf')->willReturn(new OwnerNoticesOfTheDay([], []));
        self::getContainer()->set(OwnerNotices::class, $notices);
        $this->ledger->startRound('round-1', 'sample');

        $page = $this->browser->request('GET', '/waiting?project=sample');

        self::assertSame(['You'], $page->filter('nav [aria-current="page"]')
            ->each(static fn (Crawler $entry): string => trim($entry->text())));
    }

    public function testTheYouCountAgreesWithTheListWhenANoticeEndsWhileThePageRenders(): void
    {
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-10-28T08:00:00+00:00'), new DateTimeImmutable('2026-10-28T11:00:00+00:00'));
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('waitingAndEndedOnTheDayOf')->willReturn(new OwnerNoticesOfTheDay([$notice], []));
        $notices->method('stillRunningOldestFirst')->willReturn([]);
        self::getContainer()->set(OwnerNotices::class, $notices);

        $page = $this->browser->request('GET', '/waiting');

        self::assertSame('You, 1 waiting', $page->filter('.rail a[data-k="waiting"]')->attr('aria-label'));
    }
}
