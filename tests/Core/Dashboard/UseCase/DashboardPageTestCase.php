<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\WebTestCase;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class DashboardPageTestCase extends WebTestCase
{
    protected DashboardLedger $ledger;

    protected KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->ledger = new DashboardLedger();
        self::getContainer()->set(Ledger::class, $this->ledger);
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
