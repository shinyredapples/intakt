<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

#[CoversClass(RecordingLoggerFactory::class)]
#[UsesClass(RecordingLogger::class)]
#[UsesClass(LogRecords::class)]
final class TestLoggerWiringTest extends KernelTestCase
{
    public function testTheContainersLoggerRecordsForThePhpunitRun(): void
    {
        self::assertInstanceOf(RecordingLogger::class, self::getContainer()->get(LoggerInterface::class));
    }

    public function testAKernelInTheTestEnvironmentOutsideAPhpunitRunWritesErrorsToStderr(): void
    {
        $run = new Process([PHP_BINARY, __DIR__.'/Fixtures/log-an-error-outside-phpunit.php'], dirname(__DIR__, 3), [LogRecords::FILE_VARIABLE => false]);
        $run->run();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('"message":"Disk on fire outside PHPUnit."', $run->getErrorOutput());
    }
}
