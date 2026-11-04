<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(UnassertedErrorLogExtension::class)]
#[CoversClass(WarnOnUnassertedErrors::class)]
#[CoversClass(WarnOnErrorsAfterASuite::class)]
#[CoversClass(WarnOnErrorsAfterTheRun::class)]
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

    public function testATestUsingTheTraitThatEndsWithAnUnassertedErrorRecordIsAFailedTestNamingTheRecord(): void
    {
        $run = $this->runFixture('LogsAnErrorWithTheTraitFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('There was 1 failure', $run->getOutput());
        self::assertStringContainsString('Unasserted error log record: "Disk on fire with the trait."', $run->getOutput());
        self::assertStringContainsString('LogsAnErrorWithTheTraitFixture::testItLogsAnErrorNobodyAssertsAndFailsItself', $run->getOutput());
        self::assertStringNotContainsString('warning', strtolower($run->getOutput()));
    }

    public function testATestInASeparateProcessThatEndsWithAnUnassertedErrorRecordFailsNamingTheRecord(): void
    {
        $run = $this->runFixture('LogsAnErrorInASeparateProcessFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('Unasserted error log record: "Disk on fire in isolation."', $run->getOutput());
        self::assertStringContainsString('LogsAnErrorInASeparateProcessFixture::testItLogsAnErrorNobodyAssertsInItsOwnProcess', $run->getOutput());
    }

    public function testAnUnassertedErrorRecordAfterTheRecordingLoggerTestsStillFailsTheRun(): void
    {
        $run = $this->runFixture('../RecordingLoggerTest.php', 'LogsAnErrorFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('Unasserted error log record: "Disk on fire."', $run->getOutput());
    }

    public function testATestThatLogsOnlyAWarningPasses(): void
    {
        $run = $this->runFixture('LogsAWarningFixture.php');

        self::assertSame(0, $run->getExitCode(), $run->getOutput());
    }

    public function testAnErrorLoggedAfterTheLastTestOfAClassFailsTheRunNamingTheRecordAndTheClass(): void
    {
        $run = $this->runFixture('LogsAnErrorAfterItsTestsFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('Unasserted error log record after the tests of Intakt\Tests\Support\Log\Fixtures\LogsAnErrorAfterItsTestsFixture: "Disk on fire after the tests."', $run->getOutput());
    }

    public function testAnErrorLoggedAfterTheLastTestOfAClassIsNotBlamedOnTheNextClass(): void
    {
        $run = $this->runFixture('LogsAnErrorAfterItsTestsFixture.php', 'LogsAWarningFixture.php');

        self::assertStringContainsString('after the tests of Intakt\Tests\Support\Log\Fixtures\LogsAnErrorAfterItsTestsFixture', $run->getOutput());
        self::assertStringNotContainsString('LogsAWarningFixture::testItLogsOnlyAWarning', $run->getOutput());
    }

    public function testAnErrorLoggedAfterTheLastTestSuiteFailsTheRunNamingTheRecord(): void
    {
        $run = $this->runFixtureWith('phpunit-logs-after-the-last-suite.xml', 'LogsAWarningFixture.php');

        self::assertNotSame(0, $run->getExitCode(), $run->getOutput());
        self::assertStringContainsString('Unasserted error log record after the last test suite: "Disk on fire after the last suite."', $run->getOutput());
    }

    private function runFixture(string ...$files): Process
    {
        return $this->runFixtureWith('phpunit.xml', ...$files);
    }

    private function runFixtureWith(string $configuration, string ...$files): Process
    {
        $root = dirname(__DIR__, 3);
        $tests = array_map(static fn (string $file): string => __DIR__.'/Fixtures/'.$file, $files);
        $run = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-progress', '--configuration', __DIR__.'/Fixtures/'.$configuration, ...$tests], $root);
        $run->run();

        return $run;
    }
}
