<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LoggerInterface;

#[CoversNothing]
final class LogsAnErrorInAKernelTestFixture extends KernelTestCase
{
    public function testItLogsAnErrorThroughTheContainerNobodyAsserts(): void
    {
        $this->logger()->error('Disk on fire in the kernel.');

        self::assertTrue(self::$booted);
    }

    public function testItLogsAnErrorThroughTheContainerAndAssertsIt(): void
    {
        $this->logger()->error('Disk on fire in the kernel, asserted.');

        self::assertLoggedError('error', 'Disk on fire in the kernel, asserted.');
    }

    private function logger(): LoggerInterface
    {
        $logger = self::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return $logger;
    }
}
