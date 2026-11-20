<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\MarkAnsweringQueries;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\TourCaptionsOn;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RecordingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The keyless demo is what a juror without a key plays, so the shipped recording must fill both charts on its own (#3053).
#[CoversClass(ReviewerCatches::class)]
#[CoversClass(JudgeOutcomes::class)]
final class TrendsFromTheShippedRecordingTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';
    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->flagAsReplay(CreateDemoProjectCommand::NAME);
        $this->givenTheDownloadedScriptsExist();
    }

    public function testReplayingTheShippedRecordingFillsBothTrendsCharts(): void
    {
        $this->replayed($this->shipped());

        $page = $this->browser->request('GET', '/trends');

        self::assertNotEmpty($this->rowsOf($page, '#chart-catches'), 'Which reviewer caught what has no row.');
        self::assertNotEmpty($this->rowsOf($page, '#chart-judge'), 'What the judge did has no row.');
        self::assertSame([], $page->filter('.chart .takeaway[data-no-data]')->each(static fn (Crawler $takeaway): string => $takeaway->text()));
        self::assertSame([
            ['Correctness', 'P1', '1', '1'], ['Correctness', 'P2', '3', '3'], ['Correctness', 'P3', '0', '0'],
            ['Security', 'P1', '0', '0'], ['Security', 'P2', '0', '0'], ['Security', 'P3', '0', '0'],
        ], $this->rowsOf($page, '#chart-catches'));
        self::assertSame([['Kept on the act list', '3'], ['Merged as duplicate', '0'], ['Deferred to a follow-up issue', '0'], ['Rejected', '0']], $this->rowsOf($page, '#chart-judge'));
    }

    // The recorded verdicts run again through the Review module with the stand-in vendor, because the derived rows must be what a live round would have written: the approving reviewer reports nothing, the other the findings judge_done holds, in id order, at their recorded severities.
    public function testTheShippedRowsAreWhatTheReviewModuleWritesForTheRecordedVerdicts(): void
    {
        $shipped = $this->shipped();
        $fixture = new RoundFixture(self::getContainer());
        $queue = new RecordingBus();
        $round = $fixture->startFor('feat/thing', $queue, 2);
        $reported = $this->reportedIn($shipped);
        $vendor = new VerdictPerRole([
            self::FIRST => new Verdict(Decision::Approve, [], [], []),
            self::SECOND => new Verdict(Decision::Changes, array_map(static fn (string $id, string $severity): Finding => new Finding($id, Severity::from($severity), 'src/A.php', (int) substr($id, 1), null, 'Text '.$id, FixableBy::Builder, false, null, null), array_keys($reported), $reported), [], []),
        ]);
        foreach ($queue->messages as $message) {
            $fixture->deliver($message, $vendor);
        }
        $fixture->judge(new ScriptedJudgement(new Judgement(array_map(static fn (string $id): JudgedFinding => new JudgedFinding($id, Mark::Act), array_keys($reported)))))(new JudgeRound($round));
        $written = array_map(static fn (RecordedEvent $event): RecordedLedgerEvent => new RecordedLedgerEvent('', $event->event->kind, $event->event->payload, $event->recordedAt), iterator_to_array($fixture->ledger()->readInOrder(), false));

        self::assertSame($this->reporterRowsOf($shipped->events), $this->reporterRowsOf($written));
        self::assertSame($this->keyOf($written, 'fallback'), $this->keyOf($shipped->events, 'fallback'));
        self::assertFalse($this->keyOf($shipped->events, 'fallback'));
    }

    // At a pace no clock waits for, because the mock clock's sleep only moves its moment.
    private function replayed(Recording $recording): void
    {
        $updates = new PublishedLiveEvents();
        $marks = new ReplayMarks(new MarkAnsweringQueries(ReplayMark::Replay));
        $replays = new ReplaysRecording($this->ledger, $updates, new MockClock('2026-11-20T09:00:00+00:00'), new LockFactory(new InMemoryStore()), $marks, TourCaptionsOn::captions($updates, TourCaptionsOn::tour($this->ledger, $marks, '')));

        $replays->replay($recording, 1000.0);
    }

    private function shipped(): Recording
    {
        $text = file_get_contents(dirname(__DIR__, 4).self::SHIPPED);
        self::assertIsString($text);

        return new RecordingFormat(StoredSerializerFactory::create())->read($text);
    }

    // The dropped finding too, because the reviewer reported it before the judge's check left it out.
    /**
     * @return array<string, string>
     */
    private function reportedIn(Recording $recording): array
    {
        /** @var array{findings: list<array{id: string, severity: string}>, dropped: list<array{id: string, severity: string}>} $judged */
        $judged = $this->decodedOf($recording->events, LedgerEventKind::JudgeDone);
        $reported = array_column([...$judged['findings'], ...$judged['dropped']], 'severity', 'id');
        ksort($reported, SORT_NATURAL);

        return $reported;
    }

    // Without round and reviewer ids, because the fixture's round has its own.
    /**
     * @param list<RecordedLedgerEvent> $events
     *
     * @return list<array{mixed, mixed}>
     */
    private function reporterRowsOf(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            if (LedgerEventKind::ReviewerFindingsRecorded === $event->kind) {
                /** @var array{findings: list<string>, severities: array<string, string>} $payload */
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $rows[] = [$payload['findings'], $payload['severities']];
            }
        }
        usort($rows, static fn (array $one, array $other): int => count($one[0]) <=> count($other[0]));

        return $rows;
    }

    /**
     * @param list<RecordedLedgerEvent> $events
     */
    private function keyOf(array $events, string $key): mixed
    {
        return $this->decodedOf($events, LedgerEventKind::JudgeDone)[$key] ?? null;
    }

    /**
     * @param list<RecordedLedgerEvent> $events
     *
     * @return array<string, mixed>
     */
    private function decodedOf(array $events, LedgerEventKind $kind): array
    {
        foreach ($events as $event) {
            if ($kind === $event->kind) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);

                return $decoded;
            }
        }

        self::fail(sprintf('No %s row.', $kind->value));
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Crawler $page, string $chart): array
    {
        return $page->filter($chart.' tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
    }
}
