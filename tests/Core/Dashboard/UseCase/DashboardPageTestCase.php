<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Tests\Support\WebTestCase;
use Intakt\Core\Ledger\Contract\Ledger;
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
}
