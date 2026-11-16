<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Tests\Support\PinnableClock;
use Intakt\Tests\Support\PinnableClockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;

#[CoversClass(DashboardPageTestCase::class)]
#[UsesClass(PinnableClock::class)]
#[UsesClass(PinnableClockFactory::class)]
final class DashboardPageTestCaseTest extends DashboardPageTestCase
{
    // Forced by hand, because CI met this order only at random (#2295): something created the clock before the page setup set its moment.
    public function testThePageSetupSetsTheMomentOfAClockThatWasCreatedBeforeIt(): void
    {
        self::ensureKernelShutdown();
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $created = self::getContainer()->get(ClockInterface::class);

        $this->serveFromMemory();

        self::assertSame('2026-10-28T12:00:00+00:00', $created->now()->format('c'));
    }
}
