<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayDemoCommand;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayDemoInput;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayStopped;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ReplayDemoCommand::class)]
#[CoversClass(ReplayDemoInput::class)]
#[UsesClass(ReplaysRecording::class)]
#[UsesClass(ReplayIsRefused::class)]
#[UsesClass(ReplayStopped::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(RecordingIsMalformed::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ReplayMark::class)]
final class ReplayDemoCommandTest extends TestCase
{
    private const string STREAM = '<turbo-stream action="replace" target="round-state"><template>r-1 started</template></turbo-stream>';

    private string $directory;

    private MockClock $clock;

    private ClockedLedger $ledger;

    private ClockedLiveUpdates $updates;

    private RecordingFormat $format;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-demo-replay-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->clock = new MockClock('2026-11-16T09:00:00+00:00');
        $this->ledger = new ClockedLedger($this->clock);
        $this->updates = new ClockedLiveUpdates($this->clock, $this->ledger);
        $this->format = new RecordingFormat(StoredSerializerFactory::create());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheReplayIsRefusedOnAnInstallThatIsNotTheComposeDemo(): void
    {
        $tester = $this->tester('');

        $tester->execute(['recording' => $this->recordingFile()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('only on the compose demo', $tester->getDisplay());
        self::assertSame(0, $this->appendedRows());
        self::assertSame([], $this->updates->published());
    }

    public function testOnTheComposeDemoTheRecordedRowsAreReplayedIntoTheDemoProject(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->recordingFile()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(2, $this->appendedRows());
        self::assertStringContainsString('Replayed 2 ledger events and 1 live updates of change sample-change into the demo project demo-replay, 1 times as fast as recorded.', $this->flat($tester));
    }

    public function testOnTheComposeDemoTheCapturedUpdatesArePublished(): void
    {
        $this->tester('intakt')->execute(['recording' => $this->recordingFile()]);

        self::assertSame([['review/rounds/round-1', self::STREAM]], array_map(static fn (array $published): array => [$published['topic'], $published['stream']], $this->updates->published()));
    }

    public function testTheFactorShortensTheWaitBetweenRows(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->recordingFile(), '--factor' => '8']);

        self::assertSame('09:00:05', $this->clock->now()->format('H:i:s'));
    }

    public function testAFactorBelowOneSlowsTheReplayDown(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->recordingFile(), '--factor' => '0.5']);

        self::assertSame('09:01:20', $this->clock->now()->format('H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function factorsThatAreNoSpeed(): iterable
    {
        yield 'zero' => ['0'];
        yield 'below zero' => ['-2'];
        yield 'too large to be finite' => ['1e400'];
    }

    public function testAFactorThatIsNoNumberIsRefusedByTheConsole(): void
    {
        $tester = $this->tester('intakt');

        $this->expectException(InvalidOptionException::class);

        try {
            $tester->execute(['recording' => $this->recordingFile(), '--factor' => 'fast']);
        } finally {
            self::assertSame(0, $this->appendedRows());
        }
    }

    #[DataProvider('factorsThatAreNoSpeed')]
    public function testAFactorThatIsNoNumberAboveZeroIsRefused(string $factor): void
    {
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->recordingFile(), '--factor' => $factor]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The factor must be a number above 0.', $this->flat($tester));
        self::assertSame(0, $this->appendedRows());
    }

    public function testAMalformedRecordingIsRefusedWithItsReason(): void
    {
        $file = $this->directory.'/broken.json';
        new Filesystem()->dumpFile($file, '{"events": []}');
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $file]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The recording is not a recording of a review', $this->flat($tester));
        self::assertSame(0, $this->appendedRows());
    }

    public function testARecordingThatCannotBeReadIsRefused(): void
    {
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->directory.'/missing.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing.json', $this->flat($tester));
    }

    public function testARecordingAlreadyReplayedIsRefusedWithTheRoundTheLedgerHolds(): void
    {
        $file = $this->recordingFile();
        $this->tester('intakt')->execute(['recording' => $file]);
        $again = $this->tester('intakt');

        $again->execute(['recording' => $file]);

        self::assertSame(Command::FAILURE, $again->getStatusCode());
        self::assertStringContainsString('already holds review round round-1', $this->flat($again));
        self::assertSame(2, $this->appendedRows());
    }

    public function testAFailedAppendIsReportedWithHowFarTheReplayGot(): void
    {
        $this->ledger->failAppend(2);
        $tester = $this->tester('intakt');

        $tester->execute(['recording' => $this->recordingFile()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The replay stopped after 1 of 2 ledger events: the reviewer_started row was not appended (disk full).', $this->flat($tester));
    }

    public function testAReplayIsRefusedWhileTheDemoProjectIsNoReplay(): void
    {
        $tester = $this->tester('intakt', ReplayMark::Live);

        $tester->execute(['recording' => $this->recordingFile()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The demo project demo-replay is not marked as a replay, so nothing is replayed; create it with intakt:demo:create first.', $this->flat($tester));
        self::assertSame(0, $this->appendedRows());
    }

    private function tester(string $composeService, ReplayMark $demoProject = ReplayMark::Replay): CommandTester
    {
        $marks = new ReplayMarks(new MarkAnsweringQueries($demoProject));
        $replays = new ReplaysRecording($this->ledger, $this->updates, $this->clock, new LockFactory(new InMemoryStore()), $marks, TourCaptionsOn::captions($this->updates, TourCaptionsOn::tour($this->ledger, $marks, '')), TourCaptionsOn::judged($this->ledger, $marks));
        $application = new Application();
        $application->addCommand(new ReplayDemoCommand($replays, $this->format, new Filesystem(), $composeService));

        return new CommandTester($application->find('intakt:demo:replay'));
    }

    private function recordingFile(): string
    {
        $file = $this->directory.'/recording.json';
        new Filesystem()->dumpFile($file, $this->format->write(new Recording(
            new DateTimeImmutable('2026-11-16T08:00:00+00:00'),
            'dev-main',
            ['claude-opus-5-5'],
            'sample',
            'sample-change',
            [
                new RecordedLedgerEvent('0196a000-0000-7000-8000-0000000000a1', LedgerEventKind::ReviewStarted, '{"round":"round-1","project":"sample","reviewers":1}', new DateTimeImmutable('2026-11-16T08:00:00+00:00')),
                new RecordedLedgerEvent('0196a000-0000-7000-8000-0000000000a2', LedgerEventKind::ReviewerStarted, '{"round":"round-1","reviewer":"r-1"}', new DateTimeImmutable('2026-11-16T08:00:40+00:00')),
            ],
            [new CapturedUpdate(new DateTimeImmutable('2026-11-16T08:00:40+00:00'), 'review/rounds/round-1', self::STREAM)],
        )));

        return $file;
    }

    private function appendedRows(): int
    {
        return count(iterator_to_array($this->ledger->readInOrder(), false));
    }

    private function flat(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
