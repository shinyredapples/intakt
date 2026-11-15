<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

abstract class DashboardPageTestCase extends WebTestCase
{
    protected DashboardLedger $ledger;

    protected KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->ledger = new DashboardLedger();
        self::getContainer()->set(Ledger::class, $this->ledger);
        // The ledger stamps its rows at one moment, so a round only counts as overdue in the tests that move the clock on.
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-10-28T12:00:00+00:00'));
    }
}
