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
        self::getContainer()->get(LoggerInterface::class)->error('Disk on fire in the kernel.');

        self::assertTrue(self::$booted);
    }

    public function testItLogsAnErrorThroughTheContainerAndAssertsIt(): void
    {
        self::getContainer()->get(LoggerInterface::class)->error('Disk on fire in the kernel, asserted.');

        self::assertLoggedError('error', 'Disk on fire in the kernel, asserted.');
    }
}
