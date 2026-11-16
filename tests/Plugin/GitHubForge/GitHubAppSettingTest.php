<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAppSetting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

#[CoversClass(GitHubAppSetting::class)]
final class GitHubAppSettingTest extends TestCase
{
    private const string NOTICE = 'No GitHub App is configured (GITHUB_APP_ID is empty), so verdicts are not published to GitHub; the review page and MCP show them.';

    public function testAWorkerStartedWithoutAnAppLogsTheNotice(): void
    {
        $log = new BufferingLogger();

        new GitHubAppSetting('', $log)->onWorkerStarted($this->workerStarted());

        self::assertSame([['info', self::NOTICE, []]], $log->cleanLogs());
    }

    public function testAWorkerStartedWithAnAppLogsNothing(): void
    {
        $log = new BufferingLogger();

        new GitHubAppSetting('12345', $log)->onWorkerStarted($this->workerStarted());

        self::assertSame([], $log->cleanLogs());
    }

    public function testAVerdictSkippedAfterTheWorkerStartedLogsNoSecondNotice(): void
    {
        $log = new BufferingLogger();
        $setting = new GitHubAppSetting('', $log);

        $setting->onWorkerStarted($this->workerStarted());
        $setting->whenConfigured(static function (): void {
        });

        self::assertSame([['info', self::NOTICE, []]], $log->cleanLogs());
    }

    private function workerStarted(): WorkerStartedEvent
    {
        return new WorkerStartedEvent(new Worker([], $this->createStub(MessageBusInterface::class)));
    }
}
