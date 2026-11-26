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
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRefusal;
use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoRun;
use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoRunHandler;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
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
#[CoversClass(DemoRefusal::class)]
#[CoversClass(PlayDemoRun::class)]
#[UsesClass(ReplaysRecording::class)]
#[UsesClass(ReplayIsRefused::class)]
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

    private const string PLAY = '0123456789abcdef0123456789abcdef';

    private string $directory;

    private DashboardLedger $ledger;

    private PublishedLiveEvents $updates;

    private MockClock $clock;

    private ReplayMarkBus $marks;

    public function testAPlayedDemoRunReplaysTheConfiguredRecordingIntoTheDemoProject(): void
    {
        $this->handler($this->recordingFile())($this->play());

        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::VerdictReceived, LedgerEventKind::JudgeDone], array_map(static fn (RecordedEvent $row): LedgerEventKind => $row->event->kind, $this->rows()));
        self::assertStringContainsString('"project":"'.CreateDemoProjectCommand::NAME.'"', $this->rows()[0]->event->payload);
    }

    public function testAPlayAfterTheDemoProjectWasRemovedCreatesItAgainAndPlaysTheRun(): void
    {
        $this->marks = new ReplayMarkBus();

        $this->handler($this->recordingFile())($this->play());

        self::assertSame([CreateDemoProjectCommand::NAME], $this->marks->replays());
        self::assertCount(4, $this->rows());
        self::assertStringContainsString('"project":"'.CreateDemoProjectCommand::NAME.'"', $this->rows()[0]->event->payload);
    }

    public function testASecondPlayRefusedBecauseTheFirstIsInTheLedgerTellsOnlyItsOwnPageThatAnEarlierPlayCameFirst(): void
    {
        $handler = $this->handler($this->recordingFile());
        $handler($this->play());
        $played = count($this->shellStreams());
        $second = str_repeat('e', 32);

        $handler(new PlayDemoRun($second));

        $later = array_slice($this->shellStreams(), $played);
        self::assertCount(1, $later);
        self::assertStringContainsString('target="demo-play-'.$second.'"', $later[0]);
        self::assertStringContainsString(DemoRefusal::AnotherPlayFirst->sentence(), $later[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAModelCallAnotherReviewHoldsShowsItsOwnRefusalAndNotTheEarlierPlay(): void
    {
        self::assertSame(DemoRefusal::CallUsedElsewhere, DemoRefusal::of(ReplayIsRefused::becauseAnotherReviewHoldsCall('call-1')));
    }

    public function testTheRefusalOfAModelCallAnotherReviewHoldsSaysThatAnotherReviewUsedTheCall(): void
    {
        self::assertSame('Another review in the ledger already used a model call of the demo recording, so the run was not played. The install log names the call.', DemoRefusal::CallUsedElsewhere->sentence());
    }

    public function testAModelCallAnEarlierPlayHoldsPointsAtTheEarlierPlay(): void
    {
        self::assertSame(DemoRefusal::AnotherPlayFirst, DemoRefusal::of(ReplayIsRefused::becauseTheLedgerHoldsCall('call-1')));
    }

    public function testAPlayKeepsItsSignThroughTheQueueSoItsRefusalReachesItsOwnBox(): void
    {
        $queued = unserialize(serialize($this->play()));
        self::assertInstanceOf(PlayDemoRun::class, $queued);

        $this->handler($this->recordingFile(), '')($queued);

        self::assertStringContainsString('target="demo-play-'.self::PLAY.'"', $this->shellStreams()[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAPlayQueuedBeforePlaysCarriedASignIsRefusedWithoutAnErrorAndPublishesNothing(): void
    {
        $queued = unserialize(sprintf('O:%d:"%s":0:{}', strlen(PlayDemoRun::class), PlayDemoRun::class));
        self::assertInstanceOf(PlayDemoRun::class, $queued);

        $this->handler($this->recordingFile(), '')($queued);

        self::assertSame([], $this->shellStreams());
        self::assertSame([], $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testARefusedPlayShowsItsReasonOnTheDemoPage(): void
    {
        new Filesystem()->dumpFile($this->directory.'/broken.json', '{"events": "no"}');

        $this->handler($this->directory.'/broken.json')($this->play());

        $streams = $this->shellStreams();
        self::assertCount(1, $streams);
        self::assertStringStartsWith('<turbo-stream action="update" target="demo-play-'.self::PLAY.'"><template><p class="flash flash-error" role="alert">The demo run did not play. '.DemoRefusal::Malformed->sentence(), trim($streams[0]));
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testThePlayedRunGoesAtOneAndAHalfTimesTheRecordedPace(): void
    {
        $this->handler($this->recordingFile())($this->play());

        self::assertStringContainsString('"replay_factor":1.5', $this->rows()[0]->event->payload);
        self::assertEquals(new DateTimeImmutable('2026-11-16T08:00:00+00:00')->modify('+80 seconds'), $this->clock->now());
    }

    public function testEachReplayedRowPublishesTheCaptionOfItsStageOnTheShellTopic(): void
    {
        $this->handler($this->recordingFile())($this->play());

        $captions = array_values(array_filter($this->updates->events, static fn (LiveEvent $event): bool => DashboardTopics::SHELL === $event->topic));
        self::assertSame(
            [TourStage::ChangeArrives, TourStage::ReviewersRead, TourStage::JudgeWeighs, TourStage::RoundEnds],
            array_map(static fn (LiveEvent $event): TourStage => self::stageIn($event->turboStream), $captions),
        );
        self::assertStringStartsWith('<turbo-stream action="replace" target="tour-caption"><template>', $captions[0]->turboStream);
        self::assertStringContainsString('href="/reviews/round-1"', $captions[0]->turboStream);
    }

    public function testAQueuedPlayOnAnInstallNoLongerOnTheComposeDemoAppendsNothingAndShowsItsReasonOnThePage(): void
    {
        $this->handler($this->recordingFile(), '')($this->play());

        self::assertSame([], $this->rows());
        self::assertSame([DemoReadiness::NotComposeDemo->sentence()], $this->refusalReasons());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAQueuedPlayOnAnInstallWhoseRecordingSettingWasClearedAppendsNothingAndShowsItsReasonOnThePage(): void
    {
        $this->handler('')($this->play());

        self::assertSame([], $this->rows());
        self::assertSame([DemoReadiness::NoRecording->sentence()], $this->refusalReasons());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAMissingRecordingIsLoggedAsAnErrorAndAppendsNothing(): void
    {
        $this->handler($this->directory.'/missing.json')($this->play());

        self::assertSame([], $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAMalformedRecordingIsLoggedAsAnErrorAndAppendsNothing(): void
    {
        new Filesystem()->dumpFile($this->directory.'/broken.json', '{"events": "no"}');

        $this->handler($this->directory.'/broken.json')($this->play());

        self::assertSame([], $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testASecondPlayOfTheSameRunIsRefusedAndLoggedAsAnError(): void
    {
        $handler = $this->handler($this->recordingFile());
        $handler($this->play());

        $handler($this->play());

        self::assertCount(4, $this->rows());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAPlayThatLosesTheReplayLockIsRefusedAndWritesNoReplayMark(): void
    {
        $this->marks = new ReplayMarkBus();
        $store = new InMemoryStore();
        $held = new LockFactory($store)->createLock(ReplaysRecording::LOCK, ttl: null);
        self::assertTrue($held->acquire());

        $this->handler($this->recordingFile(), 'intakt', new LockFactory($store))($this->play());

        self::assertSame([], $this->marks->replays());
        self::assertSame([], $this->rows());
        self::assertSame([DemoRefusal::Busy->sentence()], $this->refusalReasons());
        self::assertStringContainsString('target="demo-play-'.self::PLAY.'"', $this->shellStreams()[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAPlayThatLosesTheReplayLockToADemoRemovalSaysARemovalMayHoldItAndClaimsNoRun(): void
    {
        $store = new InMemoryStore();
        $removal = new LockFactory($store)->createLock(ReplaysRecording::LOCK, ttl: null);
        self::assertTrue($removal->acquire());

        $this->handler($this->recordingFile(), 'intakt', new LockFactory($store))($this->play());

        self::assertStringContainsString('demo removal', $this->refusalReasons()[0]);
        self::assertStringNotContainsString('review list', $this->refusalReasons()[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAnUnreadableRecordingShowsAFixedSentenceThatNamesNoPath(): void
    {
        $this->handler($this->directory.'/missing.json')($this->play());

        self::assertSame([DemoRefusal::Unreadable->sentence()], $this->refusalReasons());
        self::assertStringNotContainsString($this->directory, $this->shellStreams()[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testARefusedReplayShowsAFixedSentence(): void
    {
        $this->marks = new ReplayMarkBus();
        $handler = $this->handler($this->recordingWithATopiclessUpdate());

        $handler($this->play());

        self::assertSame([DemoRefusal::Refused->sentence()], $this->refusalReasons());
        self::assertSame([], $this->marks->replays());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testAStoppedReplayShowsAFixedSentence(): void
    {
        $this->ledger->failNextAppend();

        $this->handler($this->recordingFile())($this->play());

        self::assertSame([DemoRefusal::Stopped->sentence()], $this->refusalReasons());
        self::assertLoggedError('error', 'The demo run was not played.');
    }

    public function testARefusalUpdatesOnlyTheBoxOfThePlayItBelongsTo(): void
    {
        new Filesystem()->dumpFile($this->directory.'/broken.json', '{"events": "no"}');
        $other = str_repeat('f', 32);

        $this->handler($this->directory.'/broken.json')(new PlayDemoRun($other));

        self::assertStringContainsString('target="demo-play-'.$other.'"', $this->shellStreams()[0]);
        self::assertStringNotContainsString(self::PLAY, $this->shellStreams()[0]);
        self::assertLoggedError('error', 'The demo run was not played.');
    }

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

    private function handler(string $recording, string $composeService = 'intakt', ?LockFactory $locks = null): PlayDemoRunHandler
    {
        $marks = new ReplayMarks($this->marks);
        $captions = TourCaptionsOn::captions($this->updates, TourCaptionsOn::tour($this->ledger, $marks, $composeService));
        $replays = new ReplaysRecording($this->ledger, $this->updates, $this->clock, $locks ?? new LockFactory(new InMemoryStore()), $marks, $captions, TourCaptionsOn::judged($this->ledger, $marks));

        return new PlayDemoRunHandler($replays, $this->format(), new Filesystem(), new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.')), $marks, $this->marks, $captions, $recording, $composeService);
    }

    private function play(): PlayDemoRun
    {
        return new PlayDemoRun(self::PLAY);
    }

    private function format(): RecordingFormat
    {
        return new RecordingFormat(StoredSerializerFactory::create());
    }

    private function row(LedgerEventKind $kind, string $payload, int $secondsAfterStart): RecordedLedgerEvent
    {
        return new RecordedLedgerEvent('0196a000-0000-7000-8000-0000000000a'.$secondsAfterStart, $kind, $payload, new DateTimeImmutable('2026-11-16T08:00:00+00:00')->modify(sprintf('+%d seconds', $secondsAfterStart)));
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

    /**
     * @return list<string>
     */
    private function refusalReasons(): array
    {
        return array_map(static fn (string $stream): string => 1 === preg_match('~The demo run did not play\. (.*?)</p>~s', $stream, $match) ? $match[1] : self::fail('Not a refusal.'), $this->shellStreams());
    }

    /**
     * @return list<string>
     */
    private function shellStreams(): array
    {
        return array_values(array_map(static fn (LiveEvent $event): string => $event->turboStream, array_filter($this->updates->events, static fn (LiveEvent $event): bool => DashboardTopics::SHELL === $event->topic)));
    }

    private function recordingWithATopiclessUpdate(): string
    {
        $file = $this->directory.'/topicless.json';
        new Filesystem()->dumpFile($file, $this->format()->write(new Recording(new DateTimeImmutable('2026-11-16T08:00:00+00:00'), 'dev-main', ['claude-opus-5-5'], 'sample', 'sample-change', [
            $this->row(LedgerEventKind::ReviewStarted, '{"round":"round-1","project":"sample","reviewers":1,"change":"sample-change","number":1}', 0),
        ], [new CapturedUpdate(new DateTimeImmutable('2026-11-16T08:00:00+00:00'), ' ', '<turbo-stream></turbo-stream>')])));

        return $file;
    }
}
