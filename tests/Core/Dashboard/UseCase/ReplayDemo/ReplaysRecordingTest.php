<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\ReplayedStart;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\HeldCalls;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayStopped;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ReplaysRecording::class)]
#[CoversClass(ReplayIsRefused::class)]
#[CoversClass(ReplayStopped::class)]
#[CoversClass(HeldCalls::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ReplayMark::class)]
final class ReplaysRecordingTest extends TestCase
{
    private const string ROUND = '0196a000-0000-7000-8000-000000000001';

    private const string TOPIC = 'review/rounds/'.self::ROUND;

    private const string CALL = '0196a000-0000-7000-8000-0000000000c1';

    // Escaped as the stored serializer writes it, so a test sees that the rewritten row keeps the escapes.
    private const string TASK = '"Fix the cart \\/ caf\\u00e9."';

    private const string START = '2026-11-16T09:00:00.000000+00:00';

    private MockClock $clock;

    private ClockedLedger $ledger;

    private InMemoryStore $locks;

    private ClockedLiveUpdates $updates;

    private ReplayMark $demoProject = ReplayMark::Replay;

    public function testReplayingARecordingPublishesEveryCapturedUpdateWithItsRecordedTopicAndStreamInCaptureOrder(): void
    {
        $recording = $this->recordedRun();

        $this->replays()->replay($recording, 1.0);

        self::assertSame(
            array_map(static fn (CapturedUpdate $update): array => [$update->topic, $update->data], $recording->updates),
            array_map(static fn (array $published): array => [$published['topic'], $published['stream']], $this->updates->published()),
        );
    }

    public function testEachUpdateIsPublishedOnceTheClockHasAdvancedByItsRecordedGapDividedByTheFactor(): void
    {
        $this->replays()->replay($this->recordedRun(), 4.0);

        self::assertSame(['09:00:00.125000', '09:00:02.500000', '09:00:32.875000'], array_column($this->updates->published(), 'at'));
    }

    public function testEachUpdateFindsTheRowsRecordedUpToItsMomentAlreadyAppended(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertSame([1, 2, 5], array_column($this->updates->published(), 'rows'));
    }

    public function testAnUpdateWhoseMomentAnAppendHasOverrunIsPublishedWithoutWaiting(): void
    {
        $this->ledger->takeSecondsPerAppend(3.0);

        $this->replays()->replay($this->recordedRun(), 2.0);

        self::assertSame(['09:00:03.000000', '09:00:08.000000', '09:01:11.500000'], array_column($this->updates->published(), 'at'));
    }

    public function testAnUpdateCapturedBeforeTheFirstRowIsPublishedAtTheStartAndTheRowFollowsAtItsGap(): void
    {
        $this->replays()->replay($this->recording([$this->row(LedgerEventKind::ReviewerStarted, '{"round":"r"}', 4)], [$this->update(0)]), 2.0);

        self::assertSame(['09:00:00.000000'], array_column($this->updates->published(), 'at'));
        self::assertSame('09:00:02.000000', $this->appended()[0]->recordedAt->format('H:i:s.u'));
    }

    public function testAFailedAppendStopsTheReplayBeforeTheUpdatesThatFollowIt(): void
    {
        $this->ledger->failAppend(2);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay should have stopped.');
        } catch (ReplayStopped) {
        }

        self::assertSame([1], array_column($this->updates->published(), 'rows'));
    }

    public function testAReplayIntoADemoProjectThatIsNoReplayIsRefusedBeforeAnyRowIsAppended(): void
    {
        $this->demoProject = ReplayMark::Live;

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay should have been refused.');
        } catch (ReplayIsRefused $refused) {
            self::assertStringContainsString('demo-replay is not marked as a replay', $refused->getMessage());
        }

        self::assertSame([], $this->appended());
        self::assertSame([], $this->updates->published());
    }

    public function testARecordingWithABlankUpdateTopicIsRefusedBeforeAnyRowIsAppended(): void
    {
        $recording = $this->recording([$this->row(LedgerEventKind::ReviewStarted, '{"round":"r"}', 0)], [new CapturedUpdate($this->clock->now(), ' ', '<turbo-stream></turbo-stream>')]);

        try {
            $this->replays()->replay($recording, 1.0);
            self::fail('The replay should have been refused.');
        } catch (ReplayIsRefused) {
        }

        self::assertSame([], $this->appended());
        self::assertSame([], $this->updates->published());
    }

    public function testARefusedReplayPublishesNoUpdate(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);
        $again = new ClockedLiveUpdates($this->clock, $this->ledger);

        try {
            new ReplaysRecording($this->ledger, $again, $this->clock, new LockFactory($this->locks), $this->marks(), TourCaptionsOn::captions($again, TourCaptionsOn::tour($this->ledger, $this->marks(), '')), TourCaptionsOn::judged($this->ledger, $this->marks()))->replay($this->recordedRun(), 1.0);
        } catch (ReplayIsRefused) {
        }

        self::assertSame([], $again->published());
    }

    public function testAReplayRefusedWhileAnotherRunsPublishesNoUpdate(): void
    {
        // Kept in a variable, because a lock released by its destructor would let the replay run.
        $running = new LockFactory($this->locks)->createLock(ReplaysRecording::LOCK);
        $running->acquire();

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
        } catch (ReplayIsRefused) {
        }

        self::assertSame([], $this->updates->published());
    }

    public function testReplayingARecordingAppendsTheSameLedgerRowsAsTheRecordedRunInRecordedOrder(): void
    {
        $recording = $this->recordedRun();

        $this->replays()->replay($recording, 1.0);

        self::assertSame(
            array_map(static fn (RecordedLedgerEvent $event): array => [$event->kind, self::withFactor(self::withProject($event->payload, CreateDemoProjectCommand::NAME), $event->kind, '1.0')], $recording->events),
            array_map(static fn (RecordedEvent $row): array => [$row->event->kind, $row->event->payload], $this->appended()),
        );
    }

    public function testTheReplayedReviewStartedRowCarriesTheReplaysFactor(): void
    {
        $this->replays()->replay($this->recordedRun(), 2.5);

        $started = json_decode($this->appended()[0]->event->payload, true);

        self::assertIsArray($started);
        self::assertSame(2.5, $started[ReplayedStart::KEY] ?? null);
    }

    public function testAWholeFactorIsStoredAsAFloat(): void
    {
        $this->replays()->replay($this->recordedRun(), 4.0);

        self::assertStringEndsWith(',"replay_factor":4.0}', $this->appended()[0]->event->payload);
    }

    public function testOnlyTheReviewStartedRowCarriesTheFactor(): void
    {
        $this->replays()->replay($this->recordedRun(), 4.0);

        self::assertSame(
            [LedgerEventKind::ReviewStarted],
            array_values(array_map(static fn (RecordedEvent $row): LedgerEventKind => $row->event->kind, array_filter($this->appended(), static fn (RecordedEvent $row): bool => str_contains($row->event->payload, ReplayedStart::KEY)))),
        );
    }

    public function testAReplayedReviewerStartIsFoundUnderItsRound(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertCount(1, iterator_to_array($this->ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, self::ROUND), false));
    }

    public function testAReplayedReviewerStartIsNotAmongTheStartsWithoutASubject(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertSame([], iterator_to_array($this->ledger->readOfKindWithoutSubject(LedgerEventKind::ReviewerStarted), false));
    }

    public function testAReplayedRowOfAnotherKindKeepsNoSubject(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertSame([null, null, null, null], array_map(static fn (RecordedEvent $row): ?string => $row->event->subject, array_values(array_filter($this->appended(), static fn (RecordedEvent $row): bool => LedgerEventKind::ReviewerStarted !== $row->event->kind))));
    }

    public function testARowThatNamesNoProjectIsAppendedAsItsStoredText(): void
    {
        $recording = $this->recordedRun();

        $this->replays()->replay($recording, 1.0);

        self::assertSame($recording->events[1]->payload, $this->appended()[1]->event->payload);
        self::assertSame($recording->events[3]->payload, $this->appended()[3]->event->payload);
    }

    public function testAReviewStartedRowThatNamesNoRecordedProjectIsAppendedUnchangedWithoutAFactor(): void
    {
        $started = '{"round":"'.self::ROUND.'","project":"other","reviewers":2,"change":"sample-change","number":1}';
        $recording = $this->recording([$this->row(LedgerEventKind::ReviewStarted, $started, 0)]);

        $this->replays()->replay($recording, 4.0);

        self::assertSame($started, $this->appended()[0]->event->payload);
    }

    public function testTheReviewStartedRowNamesTheDemoProjectNotTheRecordedOne(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertSame(
            '{"round":"'.self::ROUND.'","project":"demo-replay","reviewers":2,"change":"sample-change","number":1,"task":'.self::TASK.',"repository":"\/app\/var\/data\/sample-repository","base":"main","quorum":{},"replay_factor":1.0}',
            $this->appended()[0]->event->payload,
        );
    }

    public function testTheUsageRowOfAModelCallNamesTheDemoProject(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        $usage = json_decode($this->appended()[2]->event->payload, true);

        self::assertIsArray($usage);
        self::assertSame(CreateDemoProjectCommand::NAME, $usage['project'] ?? null);
    }

    public function testEachRowIsAppendedOnceTheClockHasAdvancedByItsRecordedGapDividedByTheFactor(): void
    {
        $this->replays()->replay($this->recordedRun(), 4.0);

        self::assertSame(
            ['09:00:00.000000', '09:00:02.500000', '09:00:02.750000', '09:00:32.750000', '09:00:32.750000'],
            array_map(static fn (RecordedEvent $row): string => $row->recordedAt->format('H:i:s.u'), $this->appended()),
        );
    }

    public function testTheTimeAnAppendTakesIsNotAddedToTheRecordedGaps(): void
    {
        $this->ledger->takeSecondsPerAppend(3.0);

        $this->replays()->replay($this->recordedRun(), 2.0);

        self::assertSame(
            ['09:00:00.000000', '09:00:05.000000', '09:00:08.000000', '09:01:05.500000', '09:01:08.500000'],
            array_map(static fn (RecordedEvent $row): string => $row->recordedAt->format('H:i:s.u'), $this->appended()),
        );
    }

    public function testARowIsNotAppendedBeforeTheClockReachesItsMoment(): void
    {
        $this->ledger->failAppend(3);

        try {
            $this->replays()->replay($this->recordedRun(), 2.0);
        } catch (ReplayStopped) {
        }

        self::assertSame('09:00:05.000000', array_last($this->appended())?->recordedAt->format('H:i:s.u'));
        self::assertSame('09:00:05.500000', $this->clock->now()->format('H:i:s.u'));
    }

    public function testARowRecordedBeforeThePreviousOneIsAppendedWithoutWaiting(): void
    {
        $this->replays()->replay($this->recording([$this->row(LedgerEventKind::ReviewerStarted, '{"round":"r"}', 10), $this->row(LedgerEventKind::ReviewerStarted, '{"round":"r"}', 4)]), 1.0);

        self::assertSame(self::START, $this->clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAFailedAppendStopsTheReplayAndLaterRowsAreNotWritten(): void
    {
        $this->ledger->failAppend(2);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay went on after a failed append.');
        } catch (ReplayStopped $stopped) {
            self::assertStringContainsString('disk full', $stopped->getMessage());
        }

        self::assertCount(1, $this->appended());
    }

    public function testAStoppedReplayNamesTheCommandsThatClearTheRowsItWroteBeforeTheNextReplay(): void
    {
        $this->ledger->failAppend(2);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay went on after a failed append.');
        } catch (ReplayStopped $stopped) {
            self::assertStringEndsWith('The events it wrote stay in the ledger: remove them with intakt:demo:remove, then press Play, which creates the demo project again (a console replay needs intakt:demo:create first).', $stopped->getMessage());
        }
    }

    public function testAReplayWhoseFirstAppendFailsAsksForAPlainRetryAndNotForTheRemovalOfTheDemoProject(): void
    {
        $this->ledger->failAppend(1);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay went on after a failed append.');
        } catch (ReplayStopped $stopped) {
            self::assertStringEndsWith('Nothing was written, so replay this recording again.', $stopped->getMessage());
            self::assertStringNotContainsString('intakt:demo:remove', $stopped->getMessage());
        }
    }

    public function testARecordingWhoseFirstAppendFailedReplaysInFullOnADirectRetryBesideTheEarlierReplay(): void
    {
        $this->replays()->replay($this->recording([$this->row(LedgerEventKind::ReviewStarted, '{"round":"earlier-round","project":"sample"}', 0)]), 1.0);
        $this->ledger->failAppend(2);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('The replay went on after a failed append.');
        } catch (ReplayStopped) {
        }

        $this->ledger->failAppend(0);
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertCount(6, $this->appended());
    }

    public function testARecordingWhoseRoundIsAlreadyInTheLedgerIsRefusedBeforeAnyRowIsWritten(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
            self::fail('A recording whose round the ledger holds was replayed again.');
        } catch (ReplayIsRefused $refused) {
            self::assertStringContainsString(self::ROUND, $refused->getMessage());
        }

        self::assertCount(5, $this->appended());
    }

    public function testARecordingWhoseModelCallAnotherProjectsRoundHoldsIsRefusedBeforeAnyRowIsWritten(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"other","tier":"standard","round":"live-round"}'));

        $refused = $this->refusal();

        self::assertStringContainsString(self::CALL, $refused->getMessage());
        self::assertCount(1, $this->appended());
    }

    public function testAModelCallAnotherProjectsRoundHoldsIsNoEarlierPlay(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"other","tier":"standard","round":"live-round"}'));

        $refused = $this->refusal();

        self::assertFalse($refused->meansAnotherPlayCameFirst());
        self::assertSame('Model call '.self::CALL.' of this recording is already used by another review in the ledger, so it is not replayed.', $refused->getMessage());
    }

    public function testAModelCallOnlyASettledUsageOfAnotherReviewHoldsIsNoEarlierPlay(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"settled","usages":[]}'));

        $refused = $this->refusal();

        self::assertFalse($refused->meansAnotherPlayCameFirst());
        self::assertCount(1, $this->appended());
    }

    public function testAModelCallAnotherProjectsRoundHoldsBesideAnEarlierPlayOfOtherCallsIsNoEarlierPlay(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"earlier-round","project":"'.CreateDemoProjectCommand::NAME.'","reviewers":1,"change":"sample-change","number":1}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"earlier-call","phase":"started","project":"'.CreateDemoProjectCommand::NAME.'","tier":"standard","round":"earlier-round"}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"other","tier":"standard","round":"live-round"}'));

        self::assertFalse($this->refusal()->meansAnotherPlayCameFirst());
    }

    public function testAModelCallAnEarlierPlayOfThisRecordingWroteUnderTheDemoProjectMeansAnotherPlayCameFirst(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"'.CreateDemoProjectCommand::NAME.'","tier":"standard","round":"'.self::ROUND.'"}'));

        $refused = $this->refusal();

        self::assertTrue($refused->meansAnotherPlayCameFirst());
        self::assertCount(1, $this->appended());
    }

    public function testAModelCallTheDemoProjectHoldsInARoundOfAnotherRecordingIsNoEarlierPlay(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"earlier-round","project":"'.CreateDemoProjectCommand::NAME.'","reviewers":1,"change":"sample-change","number":1}'));
        $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"'.CreateDemoProjectCommand::NAME.'","tier":"standard","round":"earlier-round"}'));

        $refused = $this->refusal();

        self::assertFalse($refused->meansAnotherPlayCameFirst());
        self::assertTrue($refused->meansAnotherReviewUsedACall());
        self::assertCount(2, $this->appended());
    }

    public function testARecordingSharingAModelCallWithAnotherRecordingPlayedBeforeIsNoEarlierPlay(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        try {
            $this->replays()->replay($this->recording([
                $this->row(LedgerEventKind::ReviewStarted, '{"round":"another-round","project":"sample","reviewers":1,"change":"another-change","number":1}', 0),
                $this->row(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"sample","tier":"standard","round":"another-round"}', 1),
            ]), 1.0);
            self::fail('A recording whose model call another recording played was replayed.');
        } catch (ReplayIsRefused $refused) {
            self::assertFalse($refused->meansAnotherPlayCameFirst());
            self::assertTrue($refused->meansAnotherReviewUsedACall());
        }
    }

    public function testPlayingTheSameRecordingTwiceMeansAnotherPlayCameFirst(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertTrue($this->refusal()->meansAnotherPlayCameFirst());
    }

    public function testARecordingOfAnotherRoundIsReplayedBesideAnEarlierReplay(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        $this->replays()->replay($this->recording([$this->row(LedgerEventKind::ReviewStarted, '{"round":"another-round","project":"sample"}', 0)]), 1.0);

        self::assertCount(6, $this->appended());
    }

    public function testAReplayIsRefusedWhileAnotherReplayRuns(): void
    {
        $running = new LockFactory($this->locks)->createLock(ReplaysRecording::LOCK);
        $running->acquire();

        $this->expectException(ReplayIsRefused::class);

        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
        } finally {
            self::assertSame([], $this->appended());
        }
    }

    public function testTheReplayLetsTheNextReplayRunOnceItEnds(): void
    {
        $this->replays()->replay($this->recordedRun(), 1.0);

        self::assertTrue(new LockFactory($this->locks)->createLock(ReplaysRecording::LOCK)->acquire());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::START);
        $this->ledger = new ClockedLedger($this->clock);
        $this->locks = new InMemoryStore();
        $this->updates = new ClockedLiveUpdates($this->clock, $this->ledger);
    }

    private function marks(): ReplayMarks
    {
        return new ReplayMarks(new MarkAnsweringQueries($this->demoProject));
    }

    // Written through the stored serializer and read back from a file, as the record command leaves it, so the rows are the text a live review stores.
    private function recordedRun(): Recording
    {
        $format = new RecordingFormat(StoredSerializerFactory::create());

        return $format->read($format->write($this->recording([
            $this->row(LedgerEventKind::ReviewStarted, '{"round":"'.self::ROUND.'","project":"sample","reviewers":2,"change":"sample-change","number":1,"task":'.self::TASK.',"repository":"\/app\/var\/data\/sample-repository","base":"main","quorum":{}}', 0),
            $this->row(LedgerEventKind::ReviewerStarted, '{"round":"'.self::ROUND.'","reviewer":"r-1","role":"correctness","tier":"standard"}', 10),
            $this->row(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"started","project":"sample","tier":"standard","round":"'.self::ROUND.'","model":"claude-opus-5-5"}', 11),
            $this->row(LedgerEventKind::UsageRecorded, '{"call":"'.self::CALL.'","phase":"settled","usages":[{"model":"claude-opus-5-5","total":1.0}]}', 131),
            $this->row(LedgerEventKind::VerdictReceived, '{"round":"'.self::ROUND.'","reviewer":"r-1","role":"correctness","tier":"standard","decision":"approve","findings":0}', 131),
        ], [
            $this->update(500),
            // Captured at the moment of the reviewer_started row, so a test sees the row appended before the update that shows it.
            $this->update(10_000),
            $this->update(131_500),
        ])));
    }

    private function replays(): ReplaysRecording
    {
        return new ReplaysRecording($this->ledger, $this->updates, $this->clock, new LockFactory($this->locks), $this->marks(), TourCaptionsOn::captions($this->updates, TourCaptionsOn::tour($this->ledger, $this->marks(), '')), TourCaptionsOn::judged($this->ledger, $this->marks()));
    }

    private function refusal(): ReplayIsRefused
    {
        try {
            $this->replays()->replay($this->recordedRun(), 1.0);
        } catch (ReplayIsRefused $refused) {
            return $refused;
        }

        self::fail('A recording whose model call the ledger holds was replayed.');
    }

    private function row(LedgerEventKind $kind, string $payload, int $secondsAfterStart): RecordedLedgerEvent
    {
        return new RecordedLedgerEvent('0196a000-0000-7000-8000-0000000000aa', $kind, $payload, new DateTimeImmutable('2026-11-16T09:00:00+00:00')->modify(sprintf('+%d seconds', $secondsAfterStart)));
    }

    private function update(int $millisecondsAfterStart): CapturedUpdate
    {
        return new CapturedUpdate(
            new DateTimeImmutable('2026-11-16T09:00:00+00:00')->modify(sprintf('+%d milliseconds', $millisecondsAfterStart)),
            self::TOPIC,
            sprintf('<turbo-stream action="replace" target="round-state"><template>at %d ms</template></turbo-stream>', $millisecondsAfterStart),
        );
    }

    /**
     * @param list<RecordedLedgerEvent> $events
     * @param list<CapturedUpdate>      $updates
     */
    private function recording(array $events, array $updates = []): Recording
    {
        return new Recording(new DateTimeImmutable('2026-11-16T09:00:00+00:00'), 'dev-main', ['claude-opus-5-5'], 'sample', 'sample-change', $events, $updates);
    }

    /**
     * @return list<RecordedEvent>
     */
    private function appended(): array
    {
        return iterator_to_array($this->ledger->readInOrder(), false);
    }

    private static function withProject(string $payload, string $project): string
    {
        return str_replace('"project":"sample"', '"project":"'.$project.'"', $payload);
    }

    private static function withFactor(string $payload, LedgerEventKind $kind, string $factor): string
    {
        return LedgerEventKind::ReviewStarted === $kind ? substr($payload, 0, -1).',"replay_factor":'.$factor.'}' : $payload;
    }
}
