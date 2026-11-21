<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\MarkAnsweringQueries;
use Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo\TourCaptionsOn;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The keyless demo is what a juror without a key plays, so the shipped recording must fill both charts on its own (#3053).
#[CoversClass(ReviewerCatches::class)]
#[CoversClass(JudgeOutcomes::class)]
#[UsesClass(ReplaysRecording::class)]
#[UsesClass(RecordingFormat::class)]
#[UsesClass(Recording::class)]
#[UsesClass(ReplayMarks::class)]
final class TrendsFromTheShippedRecordingTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string SHIPPED = '/fixtures/demo/sample-change-review.json';

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

        self::assertNotEmpty($this->rowsOf($page, '#chart-catches'), 'Catches by reviewer has no row.');
        self::assertNotEmpty($this->rowsOf($page, '#chart-judge'), 'Checked findings has no row.');
        self::assertSelectorNotExists('#chart-per-day');
        self::assertSame([
            ['Correctness', 'P1', '2', '0'], ['Correctness', 'P2', '2', '2'], ['Correctness', 'P3', '0', '0'],
            ['Security', 'P1', '1', '0'], ['Security', 'P2', '2', '0'], ['Security', 'P3', '1', '1'],
        ], $this->rowsOf($page, '#chart-catches'));
        self::assertSame([['Kept to fix', '5'], ['Merged as duplicate', '3'], ['Deferred to a follow-up issue', '0'], ['Rejected', '0']], $this->rowsOf($page, '#chart-judge'));
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

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Crawler $page, string $chart): array
    {
        return $page->filter($chart.' tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
    }
}
