<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Infrastructure\WaitingCount;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(WaitingCount::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(Account::class)]
#[UsesClass(RecordingLogger::class)]
#[UsesClass(LogRecords::class)]
final class WaitingCountTest extends TestCase
{
    public function testItCountsTheNoticesStillInForce(): void
    {
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willReturn([$notice, $notice]);

        self::assertSame(2, new WaitingCount($notices, new NullLogger())->now());
    }

    public function testAnUnreadableNoticeStoreShowsNoCount(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willThrowException(new RuntimeException('no such table'));

        self::assertSame(0, new WaitingCount($notices, new NullLogger())->now());
    }

    public function testItHandsOverTheNoticesStillInForceOldestFirst(): void
    {
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willReturn([$notice]);

        self::assertSame([$notice], new WaitingCount($notices, new NullLogger())->stillRunningOldestFirst());
    }

    public function testAnUnreadableNoticeStoreHandsOverNothingWaiting(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willThrowException(new RuntimeException('no such table'));

        self::assertSame([], new WaitingCount($notices, new NullLogger())->stillRunningOldestFirst());
    }

    public function testAnUnreadableNoticeStoreLogsAWarning(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'intakt-waiting-count-');
        self::assertNotFalse($file);
        $records = new LogRecords($file);
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('stillRunningOldestFirst')->willThrowException(new RuntimeException('no such table'));

        try {
            new WaitingCount($notices, new RecordingLogger($records))->now();

            self::assertSame([['level' => 'warning', 'message' => 'The You item shows no count, because the owner notices could not be read.', 'traced' => true]], $records->all());
        } finally {
            $records->discard();
        }
    }
}
