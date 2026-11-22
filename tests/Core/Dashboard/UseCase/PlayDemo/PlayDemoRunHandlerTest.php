<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\Domain\TourStage;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\TourCaptionUpdates;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoRunHandler;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\TourCaptionsOn;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PlayDemoRunHandler::class)]
#[CoversClass(TourCaptionUpdates::class)]
#[UsesClass(ReplaysRecording::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(DemoTour::class)]
#[UsesClass(TourCaption::class)]
#[UsesClass(TourStage::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LiveEvent::class)]
final class PlayDemoRunHandlerTest extends TestCase
{
    use AssertsLoggedErrors;

    private const string START = '2026-11-16T08:00:00+00:00';

    private string $directory;

    private DashboardLedger $ledger;

    private PublishedLiveEvents $updates;

    private MockClock $clock;

    private ReplayMarkBus $marks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-play-demo-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->ledger = new DashboardLedger();
        $this->updates = new PublishedLiveEvents();
        $this->clock = new MockClock(self::START);
        $this->marks = new ReplayMarkBus([CreateDemoProjectCommand::NAME]);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testAPlayedDemoRunReplaysTheConfiguredRecordingIntoTheDemoProject(): void
    {
        $this->handler($this->recordingFile())();

        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::JudgeDone], array_map(static fn (RecordedEvent $row): LedgerEventKind => $row->event->kind, $this->rows()));
        self::assertStringContainsString('"project":"'.CreateDemoProjectCommand::NAME.'"', $this->rows()[0]->event->payload);
    }

    public function testAPlayAfterTheDemoProjectWasRemovedCreatesItAgainAndPlaysTheRun(): void
    {
        $this->marks = new ReplayMarkBus();

        $this->handler($this->recordingFile())();

        self::assertSame([CreateDemoProjectCommand::NAME], $this->marks->replays());
        self::assertCount(4, $this->rows());
        self::assertStringContainsString('"project":"'.CreateDemoProjectCommand::NAME.'"', $this->rows()[0]->event->payload);
    }

    public function testASecondPlayRefusedBecauseTheFirstIsInTheLedgerKeepsItsReasonOffThePages(): void
    {
        $handler = $this->handler($this->recordingFile());
        $handler();
        $played = count($this->shellStreams());

        $handler();

        self::assertSame([], array_slice($this->shellStreams(), $played));
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testARefusedPlayShowsItsReasonOnTheDemoPage(): void
    {
        new Filesystem()->dumpFile($this->directory.'/broken.json', '{"events": "no"}');

        $this->handler($this->directory.'/broken.json')();

        $streams = $this->shellStreams();
        self::assertCount(1, $streams);
        self::assertStringStartsWith('<turbo-stream action="update" target="demo-play-status"><template><p class="flash flash-error" role="alert">The demo run did not play. ', trim($streams[0]));
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testThePlayedRunGoesAtOneAndAHalfTimesTheRecordedPace(): void
    {
        $this->handler($this->recordingFile())();

        self::assertStringContainsString('"replay_factor":1.5', $this->rows()[0]->event->payload);
        self::assertEquals(new DateTimeImmutable('2026-11-16T08:00:00+00:00')->modify('+80 seconds'), $this->clock->now());
    }

    public function testEachReplayedRowPublishesTheCaptionOfItsStageOnTheShellTopic(): void
    {
        $this->handler($this->recordingFile())();

        $captions = array_values(array_filter($this->updates->events, static fn (LiveEvent $event): bool => DashboardTopics::SHELL === $event->topic));
        self::assertSame(
            [TourStage::ChangeArrives, TourStage::ReviewersRead, TourStage::JudgeWeighs, TourStage::RoundEnds],
            array_map(static fn (LiveEvent $event): TourStage => self::stageIn($event->turboStream), $captions),
        );
        self::assertStringStartsWith('<turbo-stream action="replace" target="tour-caption"><template>', $captions[0]->turboStream);
        self::assertStringContainsString('href="/reviews/round-1"', $captions[0]->turboStream);
    }

    public function testAQueuedPlayOnAnInstallNoLongerOnTheComposeDemoAppendsNothingAndIsLoggedAsAnError(): void
    {
        $this->handler($this->recordingFile(), '')();

        self::assertSame([], $this->rows());
        self::assertSame([], $this->updates->events);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAQueuedPlayOnAnInstallWhoseRecordingSettingWasClearedAppendsNothingAndIsLoggedAsAnError(): void
    {
        $this->handler('')();

        self::assertSame([], $this->rows());
        self::assertSame([], $this->updates->events);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAMissingRecordingIsLoggedAsAnErrorAndAppendsNothing(): void
    {
        $this->handler($this->directory.'/missing.json')();

        self::assertSame([], $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAMalformedRecordingIsLoggedAsAnErrorAndAppendsNothing(): void
    {
        new Filesystem()->dumpFile($this->directory.'/broken.json', '{"events": "no"}');

        $this->handler($this->directory.'/broken.json')();

        self::assertSame([], $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testASecondPlayOfTheSameRunIsRefusedAndLoggedAsAnError(): void
    {
        $handler = $this->handler($this->recordingFile());
        $handler();

        $handler();

        self::assertCount(4, $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    private function handler(string $recording, string $composeService = 'intakt'): PlayDemoRunHandler
    {
        $marks = new ReplayMarks($this->marks);
        $captions = TourCaptionsOn::captions($this->updates, TourCaptionsOn::tour($this->ledger, $marks, $composeService));
        $replays = new ReplaysRecording($this->ledger, $this->updates, $this->clock, new LockFactory(new InMemoryStore()), $marks, $captions, TourCaptionsOn::judged($this->ledger, $marks));

        return new PlayDemoRunHandler($replays, $this->format(), new Filesystem(), new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.')), $marks, $this->marks, $captions, $recording, $composeService);
    }

    /**
     * @return list<string>
     */
    private function shellStreams(): array
    {
        return array_values(array_map(static fn (LiveEvent $event): string => $event->turboStream, array_filter($this->updates->events, static fn (LiveEvent $event): bool => DashboardTopics::SHELL === $event->topic)));
    }

    private function recordingFile(): string
    {
        $file = $this->directory.'/recording.json';
        new Filesystem()->dumpFile($file, $this->format()->write(new Recording(new DateTimeImmutable('2026-11-16T08:00:00+00:00'), 'dev-main', ['claude-opus-5-5'], 'sample', 'sample-change', [
            $this->row(LedgerEventKind::ReviewStarted, '{"round":"round-1","project":"sample","reviewers":1,"change":"sample-change","number":1}', 0),
            $this->row(LedgerEventKind::ReviewerStarted, '{"round":"round-1","reviewer":"r-1","role":"correctness","tier":"standard"}', 20),
            $this->row(LedgerEventKind::VerdictReceived, '{"round":"round-1","reviewer":"r-1","role":"correctness","tier":"standard","decision":"changes","findings":1}', 80),
            $this->row(LedgerEventKind::JudgeDone, '{"round":"round-1","act":["f1"],"follow_ups":[],"findings":[{"id":"f1","severity":"P2","text":"Check the total."}]}', 120),
        ], [])));

        return $file;
    }

    private function row(LedgerEventKind $kind, string $payload, int $secondsAfterStart): RecordedLedgerEvent
    {
        return new RecordedLedgerEvent('0196a000-0000-7000-8000-0000000000a'.$secondsAfterStart, $kind, $payload, new DateTimeImmutable('2026-11-16T08:00:00+00:00')->modify(sprintf('+%d seconds', $secondsAfterStart)));
    }

    private function format(): RecordingFormat
    {
        return new RecordingFormat(StoredSerializerFactory::create());
    }

    /**
     * @return list<RecordedEvent>
     */
    private function rows(): array
    {
        return iterator_to_array($this->ledger->readInOrder(), false);
    }

    private static function stageIn(string $stream): TourStage
    {
        self::assertSame(1, preg_match('/data-stage="([a-z-]+)"/', $stream, $match));

        return TourStage::from($match[1] ?? self::fail('The stream names no stage.'));
    }
}
