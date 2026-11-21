<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RecordingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\ReplayMarkBus;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;

// Rows the Review module wrote itself, through a round run with the stand-in vendor the use-case tests use, because evidence from rows written by hand proves nothing about what a round records (#2981, decision 9).
#[CoversClass(ReviewerCatches::class)]
#[CoversClass(JudgeOutcomes::class)]
final class TrendsFromAReviewRoundTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    private RoundFixture $fixture;

    // The application's own ledger on the test database, so the page reads what RoundLedger wrote.
    #[Override]
    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->fixture = new RoundFixture(self::getContainer());
        self::getContainer()->set(Ledger::class, $this->fixture->ledger());
        self::getContainer()->set(ReplayMarks::class, new ReplayMarks(new ReplayMarkBus()));
        $this->givenTheDownloadedScriptsExist();
    }

    public function testBothChartsReadTheRowsARoundAndItsJudgeWrote(): void
    {
        $this->judged($this->reviewed(), new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Reject), new JudgedFinding('F3', Mark::Defer)]));

        $page = $this->browser->request('GET', '/trends');

        self::assertSame([
            'The role-1 reviewer found the most P1 findings, 1, and 1 of them no other reviewer found.',
            'The judge kept 1 of 3 findings and rejected 1.',
        ], $page->filter('#chart-catches .takeaway, #chart-judge .takeaway')->each(static fn (Crawler $takeaway): string => $takeaway->text()));
        self::assertSame([
            ['Role-1', 'P1', '1', '1'], ['Role-1', 'P2', '0', '0'], ['Role-1', 'P3', '1', '1'],
            ['Role-2', 'P1', '0', '0'], ['Role-2', 'P2', '0', '0'], ['Role-2', 'P3', '1', '1'],
        ], $this->rowsOf($page, '#chart-catches'));
        self::assertSame([['Kept to fix', '1'], ['Merged as duplicate', '0'], ['Deferred to a follow-up issue', '1'], ['Rejected', '1']], $this->rowsOf($page, '#chart-judge'));
    }

    public function testARoundTheJudgeCouldNotAnswerAddsNothingToTheJudgeChart(): void
    {
        $this->judged($this->reviewed(), new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Reject), new JudgedFinding('F3', Mark::Defer)]));
        $this->judged($this->reviewed(), Judgement::everyBlockingFindingActs([$this->finding('F4', Severity::P1, 3), $this->finding('F5', Severity::P3, 9), $this->finding('F6', Severity::P3, 20)]));

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('The judge kept 1 of 3 findings and rejected 1.', $page->filter('#chart-judge .takeaway')->text());
    }

    private function reviewed(): string
    {
        $queue = new RecordingBus();
        $round = $this->fixture->startFor('feat/thing', $queue, 2);
        $vendor = new VerdictPerRole([
            self::FIRST => $this->changes($this->finding('a', Severity::P1, 3), $this->finding('b', Severity::P3, 9)),
            self::SECOND => $this->changes($this->finding('c', Severity::P3, 20)),
        ]);

        foreach ($queue->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round;
    }

    private function judged(string $round, Judgement $judgement): void
    {
        $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round));
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }

    // At its own line, so no two findings share a place and the act list keeps each apart.
    private function finding(string $id, Severity $severity, int $line): Finding
    {
        return new Finding($id, $severity, 'src/A.php', $line, null, 'Text '.$id, FixableBy::Builder, false, null, null);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Crawler $page, string $chart): array
    {
        return $page->filter($chart.' tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
    }
}
