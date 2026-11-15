<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\CapturingLiveUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CapturingLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(RecordingFormat::class)]
final class CapturingLiveUpdatesTest extends TestCase
{
    private string $directory;

    private BufferingLogger $log;

    /** @var list<LiveEvent> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-live-capture-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->log = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testEveryUpdateStillReachesTheHub(): void
    {
        $event = new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>');

        $this->updates($this->directory.'/capture.jsonl')->publish($event);

        self::assertSame([$event], $this->published);
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

        self::assertSame([], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
        self::assertCount(1, $this->published);
    }

    public function testACaptureThatCannotBeWrittenIsLoggedAndTheUpdateStillPublished(): void
    {
        new Filesystem()->dumpFile($this->directory.'/taken', '');

        $this->updates($this->directory.'/taken/capture.jsonl')->publish(new LiveEvent('review/rounds/r-1', '<turbo-stream></turbo-stream>'));

        self::assertCount(1, $this->published);
        $logs = $this->log->cleanLogs();
        self::assertSame(['warning', 'A live dashboard update was not captured.'], [$logs[0][0], $logs[0][1]]);
    }

    private function updates(string $capture): CapturingLiveUpdates
    {
        $hub = new class($this->published) implements LiveUpdates {
            /**
             * @param list<LiveEvent> $published
             */
            public function __construct(private array &$published)
            {
            }

            #[Override]
            public function publish(LiveEvent $event): void
            {
                $this->published[] = $event;
            }
        };

        return new CapturingLiveUpdates($hub, $capture, $this->format(), new Filesystem(), new MockClock('2026-11-01T10:00:00+00:00'), $this->log);
    }

    private function format(): RecordingFormat
    {
        return new RecordingFormat(StoredSerializerFactory::create());
    }
}
