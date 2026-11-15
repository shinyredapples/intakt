<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

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
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-28T12:00:00+00:00'));
        // Answered without the database, because these pages read every other fact from the ledger in memory and a page test creates no schema.
        $this->replayMarks = new ReplayMarkBus();
        self::getContainer()->set(ReplayMarks::class, new ReplayMarks($this->replayMarks));
    }

    protected function flagAsReplay(string $project): void
    {
        $this->replayMarks->flag($project);
    }

    // Symfony 8.1 logs an uncaught client error (4xx) at error and 8.2 at warning, so the record counts at either level while both are supported.
    protected static function assertLoggedClientError(string $message): void
    {
        $records = LogRecords::ofThisRun();
        self::assertNotNull($records, 'No test log records: run the tests with the UnassertedErrorLogExtension.');
        self::assertTrue(
            $records->remove(LogLevel::ERROR, $message) || $records->remove(LogLevel::WARNING, $message),
            sprintf('Expected an error or warning log record "%s", none was logged.', $message),
        );
    }
}
