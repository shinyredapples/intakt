<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(UnassertedErrorLogExtension::class)]
#[CoversClass(WarnOnUnassertedErrors::class)]
#[UsesClass(LogRecords::class)]
#[UsesClass(RecordingLogger::class)]
final class UnassertedErrorLogExtensionTest extends TestCase
{
    public function testATestThatEndsWithAnUnassertedErrorRecordFailsNamingTheRecord(): void
    {
        $run = $this->runFixture('LogsAnErrorFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('Unasserted error log record: "Disk on fire."', $run->getOutput());
        self::assertStringContainsString('LogsAnErrorFixture::testItLogsAnErrorNobodyAsserts', $run->getOutput());
    }

    public function testATestThatLogsOnlyAWarningPasses(): void
    {
        $run = $this->runFixture('LogsAWarningFixture.php');

        self::assertSame(0, $run->getExitCode(), $run->getOutput());
    }

    private function runFixture(string $file): Process
    {
        $root = dirname(__DIR__, 3);
        $run = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', '--configuration', __DIR__.'/Fixtures/phpunit.xml', __DIR__.'/Fixtures/'.$file], $root);
        $run->run();

        return $run;
    }
}
