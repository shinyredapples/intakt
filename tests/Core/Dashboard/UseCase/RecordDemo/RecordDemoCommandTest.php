<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use Composer\InstalledVersions;
use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayedReviewRows;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordDemoCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordDemoInput;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingNeedsCapturedUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\ReviewRows;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RecordDemoCommand::class)]
#[CoversClass(ReplayedReviewRows::class)]
#[CoversClass(CapturedUpdates::class)]
#[CoversClass(RecordingNeedsCapturedUpdates::class)]
#[UsesClass(ReviewRows::class)]
#[UsesClass(RecordDemoInput::class)]
#[UsesClass(Recording::class)]
#[UsesClass(RecordedLedgerEvent::class)]
#[UsesClass(CapturedUpdate::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReviewedRound::class)]
final class RecordDemoCommandTest extends TestCase
{
    private const string FIRST = '0196a000-0000-7000-8000-000000000001';

    private const string SECOND = '0196a000-0000-7000-8000-000000000002';

    private const string OTHER = '0196a000-0000-7000-8000-000000000003';

    private string $directory;

    private DashboardLedger $ledger;

    private RecordingFormat $format;

    public function testTheRecordingHoldsTheLedgerRowsOfBothRoundsOfTheChangeInLedgerOrder(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $this->ledger->startRoundOf('other-change', self::OTHER, 1);
        $this->ledger->startReviewer(self::OTHER, 'security');

        $recording = $this->recorded();

        self::assertSame(
            [LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::UsageRecorded, LedgerEventKind::UsageRecorded, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewStarted, LedgerEventKind::ReviewerStarted, LedgerEventKind::UsageRecorded, LedgerEventKind::UsageRecorded, LedgerEventKind::VerdictReceived],
            array_map(static fn (RecordedLedgerEvent $event): LedgerEventKind => $event->kind, $recording->events),
        );
    }

    public function testEachRecordedRowKeepsItsStoredIdPayloadAndMoment(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $stored = array_first(iterator_to_array($this->ledger->readInOrder(), false));

        $first = array_first($this->recorded()->events);

        self::assertNotNull($stored);
        self::assertNotNull($first);
        self::assertSame([$stored->id->toRfc4122(), $stored->event->payload], [$first->id, $first->payload]);
        self::assertEquals($stored->recordedAt, $first->recordedAt);
    }

    public function testTheRecordingIsDatedByTheStartOfItsFirstRound(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:00:00+00:00'), $this->recorded()->reviewedAt);
    }

    public function testTheRecordingNamesEachModelTheRoundsCalledOnce(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $this->ledger->useRecordedTokens(self::OTHER, ['total' => 1], 'model-of-another-change');

        self::assertSame(['claude-opus-5-5', 'gpt-5'], $this->recorded()->models);
    }

    public function testTheRecordingNamesTheIntaktVersionThatRecordedIt(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $root = InstalledVersions::getRootPackage();

        self::assertSame($root['pretty_version'].(null === $root['reference'] ? '' : '@'.$root['reference']), $this->recorded()->intaktVersion);
    }

    public function testTheRecordingNamesTheProjectAndTheChange(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        $recording = $this->recorded();

        self::assertSame(['alpha', 'sample-change'], [$recording->project, $recording->change]);
    }

    public function testTheRecordingHoldsOnlyTheCapturedUpdatesOfTheRoundsInCaptureOrder(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        $updates = $this->recorded()->updates;

        self::assertSame(
            [[MercureRoundUpdates::topicOf(self::FIRST), '<turbo-stream>first</turbo-stream>'], [MercureRoundUpdates::topicOf(self::SECOND), '<turbo-stream>second</turbo-stream>']],
            array_map(static fn (CapturedUpdate $update): array => [$update->topic, $update->data], $updates),
        );
    }

    public function testTheCommandSaysWhatItRecorded(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $tester = $this->tester($this->capture());

        $tester->execute(['project' => 'alpha', 'change' => 'sample-change', 'recording' => $this->directory.'/recording.json']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Recorded 10 ledger events and 2 live updates of 2 review rounds into', $tester->getDisplay());
    }

    public function testAChangeWithoutRoundsInTheLedgerWritesNoRecording(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        $this->assertRefused($this->capture(), 'No review round of change unknown-change in project alpha is in the ledger.', 'unknown-change');
    }

    public function testARoundOfTheSameChangeInAnotherProjectIsNotTheChangesReview(): void
    {
        $this->ledger->startRound(self::FIRST, 'beta');

        $this->assertRefused($this->capture(), 'No review round of change sample-change in project alpha is in the ledger.');
    }

    public function testWithoutACaptureFileNoRecordingIsWritten(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        $this->assertRefused('', 'Set INTAKT_LIVE_CAPTURE to the file the live updates were captured in.');
    }

    public function testAMissingCaptureFileWritesNoRecording(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();

        $this->assertRefused($this->directory.'/missing.jsonl', 'cannot be read');
    }

    public function testACaptureWithoutUpdatesOfTheRoundsWritesNoRecording(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $capture = $this->directory.'/other.jsonl';
        new Filesystem()->dumpFile($capture, $this->format->lineOf(new CapturedUpdate(new DateTimeImmutable('2026-10-28T12:00:01+00:00'), MercureRoundUpdates::topicOf(self::OTHER), '<turbo-stream>other</turbo-stream>')));

        $this->assertRefused($capture, 'holds no live update of review round '.self::FIRST.'.');
    }

    public function testACaptureStartedAfterTheFirstRoundWritesNoRecordingAndNamesThatRound(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $capture = $this->directory.'/late.jsonl';
        new Filesystem()->dumpFile($capture, $this->format->lineOf(new CapturedUpdate(new DateTimeImmutable('2026-10-28T12:00:03+00:00'), MercureRoundUpdates::topicOf(self::SECOND), '<turbo-stream>second</turbo-stream>')));

        $this->assertRefused($capture, 'holds no live update of review round '.self::FIRST.'.');
    }

    public function testACaptureWithALineThatIsNoUpdateWritesNoRecordingAndNamesTheLine(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        $capture = $this->capture();
        new Filesystem()->appendToFile($capture, "{\"at\":\"2026-10-28T12:00:0\n");

        $this->assertRefused($capture, 'Line 4 of');
    }

    public function testARecordingThatCannotBeWrittenIsReported(): void
    {
        $this->givenTwoRoundsOfTheSampleChange();
        new Filesystem()->dumpFile($this->directory.'/taken', '');
        $tester = $this->tester($this->capture());

        $tester->execute(['project' => 'alpha', 'change' => 'sample-change', 'recording' => $this->directory.'/taken/recording.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-demo-record-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->ledger = new DashboardLedger();
        $this->format = new RecordingFormat(StoredSerializerFactory::create());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function givenTwoRoundsOfTheSampleChange(): void
    {
        $this->ledger->startRoundOf('sample-change', self::FIRST, 1);
        $this->ledger->startReviewer(self::FIRST, 'correctness');
        $this->ledger->useRecordedTokens(self::FIRST, ['total' => 10], 'claude-opus-5-5');
        $this->ledger->answer(self::FIRST, 'correctness', 1);
        $this->ledger->startRoundOf('sample-change', self::SECOND, 2);
        $this->ledger->startReviewer(self::SECOND, 'security');
        $this->ledger->useRecordedTokens(self::SECOND, ['total' => 20], 'gpt-5');
        $this->ledger->answer(self::SECOND, 'security', 0);
    }

    private function capture(): string
    {
        $capture = $this->directory.'/capture.jsonl';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($capture, $this->format->lineOf(new CapturedUpdate(new DateTimeImmutable('2026-10-28T12:00:01.25+00:00'), MercureRoundUpdates::topicOf(self::FIRST), '<turbo-stream>first</turbo-stream>')));
        $filesystem->appendToFile($capture, $this->format->lineOf(new CapturedUpdate(new DateTimeImmutable('2026-10-28T12:00:02+00:00'), 'dashboard/projects', '<turbo-stream>shell</turbo-stream>')));
        $filesystem->appendToFile($capture, $this->format->lineOf(new CapturedUpdate(new DateTimeImmutable('2026-10-28T12:00:03+00:00'), MercureRoundUpdates::topicOf(self::SECOND), '<turbo-stream>second</turbo-stream>')));

        return $capture;
    }

    private function recorded(): Recording
    {
        $file = $this->directory.'/recording.json';
        $tester = $this->tester($this->capture());

        $tester->execute(['project' => 'alpha', 'change' => 'sample-change', 'recording' => $file]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $this->format->read(new Filesystem()->readFile($file));
    }

    private function assertRefused(string $capture, string $message, string $change = 'sample-change'): void
    {
        $tester = $this->tester($capture);
        $file = $this->directory.'/recording.json';

        $tester->execute(['project' => 'alpha', 'change' => $change, 'recording' => $file]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertFileDoesNotExist($file);
    }

    private function tester(string $capture): CommandTester
    {
        $filesystem = new Filesystem();
        $application = new Application();
        $application->addCommand(new RecordDemoCommand(
            new ReplayedReviewRows($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create()))),
            new CapturedUpdates($capture, $this->format, $filesystem),
            $this->format,
            $filesystem,
        ));

        return new CommandTester($application->find('intakt:demo:record'));
    }
}
