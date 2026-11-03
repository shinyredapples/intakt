<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(RecordingLogger::class)]
final class TestLoggerWiringTest extends KernelTestCase
{
    public function testTheContainersLoggerRecordsInMemoryInTheTestEnvironment(): void
    {
        self::assertInstanceOf(RecordingLogger::class, self::getContainer()->get(LoggerInterface::class));
    }
}
