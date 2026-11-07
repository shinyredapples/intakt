<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(KernelTestCase::class)]
#[UsesClass(AssertsLoggedErrors::class)]
#[UsesClass(LogRecords::class)]
#[UsesClass(RecordingLogger::class)]
// Because each run keeps its result cache under the TMPDIR of its test worker, not in the checkout.
final class KernelTestCaseTest extends TestCase
{
    public function testAKernelTestThatEndsWithAnUnassertedErrorFromTheContainersLoggerFails(): void
    {
        $run = $this->runFixture('testItLogsAnErrorThroughTheContainerNobodyAsserts');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('There was 1 failure', $run->getOutput());
        self::assertStringContainsString('Unasserted error log record: "Disk on fire in the kernel."', $run->getOutput());
    }

    public function testAKernelTestThatAssertsItsErrorPasses(): void
    {
        $run = $this->runFixture('testItLogsAnErrorThroughTheContainerAndAssertsIt');

        self::assertSame(0, $run->getExitCode(), $run->getOutput());
    }

    private function runFixture(string $test): Process
    {
        $root = dirname(__DIR__, 2);
        $fixtures = __DIR__.'/Log/Fixtures/';
        $run = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', '--cache-directory', sys_get_temp_dir().'/phpunit-cache', '--configuration', $fixtures.'phpunit-kernel.xml', '--filter', $test, $fixtures.'LogsAnErrorInAKernelTestFixture.php'], $root);
        $run->run();

        return $run;
    }
}
