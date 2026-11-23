<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Fiber;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\CapturingLiveUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CapturingLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(RecordingFormat::class)]
final class CapturingLiveUpdatesTest extends TestCase
{
    private string $directory;

    private BufferingLogger $log;

    private PublishedLiveEvents $hub;

    public function testEveryUpdateStillReachesTheHub(): void
    {
        $event = new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>');

        $this->updates($this->directory.'/capture.jsonl')->publish($event);

        self::assertSame([$event], $this->hub->events);
    }

    public function testEachUpdateIsAppendedAsOneLineWithItsMomentTopicAndStream(): void
    {
        $capture = $this->directory.'/capture.jsonl';
        $updates = $this->updates($capture);

        $updates->publish(new LiveEvent('review/rounds/r-1', "<turbo-stream>\none</turbo-stream>"));
        $updates->publish(new LiveEvent('dashboard/projects', '<turbo-stream>two</turbo-stream>'));

        $lines = explode("\n", rtrim(new Filesystem()->readFile($capture)));
        self::assertCount(2, $lines);
        self::assertEquals(new CapturedUpdate(new DateTimeImmutable('2026-11-01T10:00:00+00:00'), 'review/rounds/r-1', "<turbo-stream>\none</turbo-stream>"), $this->format()->updateOf($lines[0]));
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testWithoutACaptureFileNothingIsWritten(): void
    {
        $this->updates('')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>'));

        self::assertSame(['.', '..'], scandir($this->directory));
        self::assertCount(1, $this->hub->events);
    }

    public function testACaptureThatCannotBeWrittenIsLoggedAndTheUpdateStillPublished(): void
    {
        new Filesystem()->dumpFile($this->directory.'/taken', '');

        $this->updates($this->directory.'/taken/capture.jsonl')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>'));

        self::assertCount(1, $this->hub->events);
        self::assertSame([['warning', 'A live dashboard update was not captured.']], array_map(static fn (mixed $record): array => is_array($record) ? [$record[0] ?? null, $record[1] ?? null] : [], $this->log->cleanLogs()));
    }

    public function testTwoWorkersPublishingAtOnceAreCapturedInTheOrderTheHubReceivedThem(): void
    {
        $capture = $this->directory.'/capture.jsonl';
        $hub = new SuspendingLiveEvents('<turbo-stream>first</turbo-stream>');
        $updates = new CapturingLiveUpdates($hub, $capture, $this->format(), new Filesystem(), new MockClock('2026-11-01T10:00:00+00:00'), new LockFactory(new FiberWaitingLockStore()), $this->log);
        $first = new Fiber(static fn () => $updates->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream>first</turbo-stream>')));
        $second = new Fiber(static fn () => $updates->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream>second</turbo-stream>')));

        $first->start();
        $second->start();
        $first->resume();

        if (!$second->isTerminated()) {
            $second->resume();
        }

        $captured = array_map(fn (string $line): ?string => $this->format()->updateOf($line)?->data, explode("\n", rtrim(new Filesystem()->readFile($capture))));
        self::assertSame(['<turbo-stream>first</turbo-stream>', '<turbo-stream>second</turbo-stream>'], $hub->received);
        self::assertSame($hub->received, $captured);
    }

    public function testACaptureLockThatCannotBeTakenIsLoggedAndTheUpdateStillPublished(): void
    {
        $store = self::createStub(BlockingStoreInterface::class);
        $store->method('waitAndSave')->willThrowException(new LockStorageException('The lock store is gone.'));
        $capture = $this->directory.'/capture.jsonl';

        new CapturingLiveUpdates($this->hub, $capture, $this->format(), new Filesystem(), new MockClock('2026-11-01T10:00:00+00:00'), new LockFactory($store), $this->log)->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>'));

        self::assertCount(1, $this->hub->events);
        self::assertFileDoesNotExist($capture);
        self::assertSame([['warning', 'A live dashboard update was not captured.']], array_map(static fn (mixed $record): array => is_array($record) ? [$record[0] ?? null, $record[1] ?? null] : [], $this->log->cleanLogs()));
    }

    public function testAnUpdateTheHubTookIsReportedAsDelivered(): void
    {
        self::assertTrue($this->updates($this->directory.'/capture.jsonl')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>')));
    }

    public function testAnUpdateTheHubRefusedIsReportedAsNotDeliveredWhileCapturing(): void
    {
        self::assertFalse($this->updatesOverARefusingHub($this->directory.'/capture.jsonl')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>')));
    }

    public function testAnUpdateTheHubRefusedIsReportedAsNotDeliveredWithoutACaptureFile(): void
    {
        self::assertFalse($this->updatesOverARefusingHub('')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>')));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-live-capture-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->log = new BufferingLogger();
        $this->hub = new PublishedLiveEvents();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function updates(string $capture): CapturingLiveUpdates
    {
        return new CapturingLiveUpdates($this->hub, $capture, $this->format(), new Filesystem(), new MockClock('2026-11-01T10:00:00+00:00'), new LockFactory(new InMemoryStore()), $this->log);
    }

    private function updatesOverARefusingHub(string $capture): CapturingLiveUpdates
    {
        $hub = self::createStub(LiveUpdates::class);
        $hub->method('publish')->willReturn(false);

        return new CapturingLiveUpdates($hub, $capture, $this->format(), new Filesystem(), new MockClock('2026-11-01T10:00:00+00:00'), new LockFactory(new InMemoryStore()), $this->log);
    }

    private function format(): RecordingFormat
    {
        return new RecordingFormat(StoredSerializerFactory::create());
    }
}
