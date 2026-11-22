<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingFormat::class)]
#[CoversClass(RecordingIsMalformed::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
final class RecordingFormatTest extends TestCase
{
    public function testARecordingReadsBackAsTheSameRecording(): void
    {
        $format = self::format();

        self::assertEquals(self::recording(), $format->read($format->write(self::recording())));
    }

    public function testARecordingReadBackIsWrittenToTheSameText(): void
    {
        $format = self::format();
        $written = $format->write(self::recording());

        self::assertSame($written, $format->write($format->read($written)));
    }

    public function testMomentsKeepTheirMicrosecondsInUtc(): void
    {
        $written = self::format()->write(self::recording());

        self::assertStringContainsString('"recorded_at": "2026-11-01T10:00:00.123456+00:00"', $written);
    }

    public function testAPayloadIsKeptAsTheStoredText(): void
    {
        $read = self::format()->read(self::format()->write(self::recording()));

        self::assertSame(['{"round":"r-1","note":"café \/ <b>"}'], array_map(static fn (RecordedLedgerEvent $event): string => $event->payload, $read->events));
    }

    public function testACapturedUpdateReadsBackFromItsLine(): void
    {
        $update = new CapturedUpdate(new DateTimeImmutable('2026-11-01T10:00:00.5+00:00'), 'review/rounds/r-1', "<turbo-stream>\nline</turbo-stream>");

        $line = self::format()->lineOf($update);

        self::assertSame(1, substr_count($line, "\n"));
        self::assertEquals($update, self::format()->updateOf(rtrim($line)));
    }

    public function testALineThatIsNoUpdateReadsAsNone(): void
    {
        self::assertNull(self::format()->updateOf('{"topic":"review/rounds/r-1"}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a list' => ['[]'];
        yield 'no events' => [self::without('events')];
        yield 'an unknown event kind' => [str_replace('"review_started"', '"review_begun"', self::written())];
        yield 'a model that is no text' => [str_replace('"claude-opus-5-5"', '5', self::written())];
        yield 'a moment in another form' => [str_replace('2026-11-01T10:00:00.123456+00:00', '1 November 2026', self::written())];
    }

    #[DataProvider('malformed')]
    public function testARecordingThatIsNotOneIsRefused(string $json): void
    {
        $this->expectException(RecordingIsMalformed::class);

        self::format()->read($json);
    }

    private static function without(string $key): string
    {
        $decoded = json_decode(self::written(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        unset($decoded[$key]);

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private static function written(): string
    {
        return self::format()->write(self::recording());
    }

    private static function format(): RecordingFormat
    {
        return new RecordingFormat(StoredSerializerFactory::create());
    }

    private static function recording(): Recording
    {
        return new Recording(
            new DateTimeImmutable('2026-11-01T10:00:00.123456+00:00'),
            'dev-main@0123456789abcdef',
            ['claude-opus-5-5', 'gpt-5'],
            'sample',
            'sample-change',
            [new RecordedLedgerEvent('0196a000-0000-7000-8000-000000000001', LedgerEventKind::ReviewStarted, '{"round":"r-1","note":"café \/ <b>"}', new DateTimeImmutable('2026-11-01T10:00:00.123456+00:00'))],
            [new CapturedUpdate(new DateTimeImmutable('2026-11-01T10:00:01+00:00'), 'review/rounds/r-1', '<turbo-stream action="replace"></turbo-stream>')],
        );
    }
}
