<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAppSetting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(GitHubAppSetting::class)]
final class GitHubAppSettingTest extends TestCase
{
    private const string NOTICE = 'No GitHub App is configured (GITHUB_APP_ID is empty), so verdicts are not published to GitHub; the review page and MCP show them.';

    public function testAWorkerStartedWithoutAnAppLogsTheNotice(): void
    {
        $log = new BufferingLogger();

        new GitHubAppSetting('', $log)->onWorkerStarted();

        self::assertSame([['info', self::NOTICE, []]], $log->cleanLogs());
    }

    public function testAWorkerStartedWithAnAppLogsNothing(): void
    {
        $log = new BufferingLogger();

        new GitHubAppSetting('12345', $log)->onWorkerStarted();

        self::assertSame([], $log->cleanLogs());
    }

    public function testAVerdictSkippedAfterTheWorkerStartedLogsNoSecondNotice(): void
    {
        $log = new BufferingLogger();
        $setting = new GitHubAppSetting('', $log);

        $setting->onWorkerStarted();
        $setting->whenConfigured(static function (): void {
        });

        self::assertSame([['info', self::NOTICE, []]], $log->cleanLogs());
    }

    public function testACallWhenConfiguredRunsWhenAnAppIsSet(): void
    {
        $ran = false;

        new GitHubAppSetting('12345', new BufferingLogger())->whenConfigured(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    public function testACallWhenConfiguredIsSkippedWithoutAnApp(): void
    {
        $ran = false;

        new GitHubAppSetting('', new BufferingLogger())->whenConfigured(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
    }

    public function testAnAppIdOfOnlyWhitespaceCountsAsMissing(): void
    {
        $log = new BufferingLogger();

        new GitHubAppSetting(" \t", $log)->onWorkerStarted();

        self::assertSame([['info', self::NOTICE, []]], $log->cleanLogs());
    }

    public function testACallWhenConfiguredIsSkippedForAnAppIdOfOnlyWhitespace(): void
    {
        $ran = false;

        new GitHubAppSetting(" \t", new BufferingLogger())->whenConfigured(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
    }
}
