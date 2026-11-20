<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ListWaiting;

use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\UseCase\ListWaiting\ListWaitingController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListWaitingController::class)]
final class WaitingCurrentPageRenderTest extends DashboardPageTestCase
{
    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->ledger = new DashboardLedger();
        self::getContainer()->set(Ledger::class, $this->ledger);
    }

    public function testOnlyWaitingIsMarkedAsTheCurrentPage(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willReturn([]);
        self::getContainer()->set(OwnerNotices::class, $notices);
        $this->ledger->startRound('round-1', 'sample');

        $page = $this->browser->request('GET', '/waiting?project=sample');

        self::assertSame(['Waiting on you'], $page->filter('nav [aria-current="page"]')
            ->each(static fn (Crawler $entry): string => trim($entry->text())));
    }
}
