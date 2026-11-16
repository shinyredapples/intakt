<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class DashboardPageTestCase extends WebTestCase
{
    protected DashboardLedger $ledger;

    protected KernelBrowser $browser;

    private ReplayMarkBus $replayMarks;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->ledger = new DashboardLedger();
        self::getContainer()->set(Ledger::class, $this->ledger);
        // The ledger stamps its rows at one moment, so a round only counts as overdue in the tests that move the clock on.
        self::clockAt('2026-10-28T12:00:00+00:00');
        // Answered without the database, because these pages read every other fact from the ledger in memory and a page test creates no schema.
        $this->replayMarks = new ReplayMarkBus();
        self::getContainer()->set(ReplayMarks::class, new ReplayMarks($this->replayMarks));
    }

    protected function flagAsReplay(string $project): void
    {
        $this->replayMarks->flag($project);
    }

    protected function storeRoundAs(string $round, ReplayMark $mark): void
    {
        $this->replayMarks->store($round, $mark);
    }
}
