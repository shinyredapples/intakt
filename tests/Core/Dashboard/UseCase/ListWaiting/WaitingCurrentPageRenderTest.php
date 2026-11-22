<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ListWaiting;

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

        self::assertSame(['Waiting on you'], $page->filter('nav [aria-current="page"]')
            ->each(static fn (Crawler $entry): string => trim($entry->text())));
    }
}
