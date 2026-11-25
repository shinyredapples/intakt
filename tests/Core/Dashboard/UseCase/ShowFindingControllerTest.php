<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\UseCase\ShowFinding\ShowFindingController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowFindingController::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(FindingUrgency::class)]
final class ShowFindingControllerTest extends DashboardPageTestCase
{
    public function testAFindingsPageLeadsWithItsUrgencyHeadlineAndPlace(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Urgent · one reviewer', 'The total is off by one.', 'src/A.php:7'],
            [$crawler->filter('.finding-head .kicker')->text(), $crawler->filter('.finding-head h1')->text(), $crawler->filter('.finding-head .finding-place')->text()],
        );
    }

    public function testATextThatGoesOnAfterTheHeadlineIsShownWhole(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame('The total is off by one. It drops the last item.', $crawler->filter('.finding-words')->text());
    }

    public function testEachReviewerWhoReportedItIsNamedAndTheJudgesCallFollows(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(1, $crawler->filter('.finding-reporters li')->count());
        self::assertStringContainsString('correctness', $crawler->filter('.finding-reporters li')->text());
        self::assertStringContainsString('Fix now', $crawler->filter('.finding-judge')->text());
    }

    public function testAReviewerWhoReportedOnlyAMergedIdShowsTheSeverityTheyGaveIt(): void
    {
        $this->givenTwoReportsJudgedAsOneEntry(['F2' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(
            ['reported as P1', 'reported as P2'],
            $crawler->filter('.finding-reporters li')->each(static fn (Crawler $reporter): string => array_last(explode(' · ', $reporter->text()))),
        );
        self::assertStringContainsString('kept once for 2 reports (F2 was the same)', $crawler->filter('.finding-judge')->text());
    }

    public function testAReviewerWhoReportedTheEntryAndAMergedIdShowsBothSeverities(): void
    {
        $this->givenTwoReportsJudgedAsOneEntry(['F1' => 'P3', 'F2' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertStringEndsWith('reported as P3 and P2', $crawler->filter('.finding-reporters li')->last()->text());
    }

    public function testTheJudgesCardCountsOneReportPerReviewerAndId(): void
    {
        $this->givenAChangesRound();
        $this->ledger->reportRatedFindings('round-1', 'correctness', ['F1' => 'P1']);
        $this->ledger->reportRatedFindings('round-1', 'security', ['F1' => 'P3', 'F6' => 'P2']);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F6');

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertStringEndsWith('reported as P3 and P2', $crawler->filter('.finding-reporters li')->last()->text());
        self::assertStringContainsString('kept once for 3 reports (F6 was the same)', $crawler->filter('.finding-judge')->text());
    }

    public function testTheJudgesCardCountsIdsWhenWhoReportedWhatIsNotRecorded(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertStringContainsString('kept once for 2 ids (F2 was the same)', $crawler->filter('.finding-judge')->text());
    }

    public function testAFindingIdWithASlashOpensFromItsRow(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', ['php/F 1?' => 'P1']);
        $this->browser->disableReboot();

        $row = $this->browser->request('GET', '/reviews/round-1')->filter('#review-to-fix a.fix-row');
        $crawler = $this->browser->request('GET', (string) $row->attr('href'));

        self::assertSame('Text of php/F 1?.', $crawler->filter('.finding-head h1')->text());
    }

    public function testAPassCheckedBeforeIntaktKeptWhoReportedWhatSaysSo(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(['Urgent', 0], [$crawler->filter('.finding-head .kicker')->text(), $crawler->filter('.finding-words')->count()]);
        self::assertStringStartsWith('Not recorded', $crawler->filter('#finding-who + p')->text());
    }

    public function testTheFindingsPageLeadsBackToItsReview(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(['/reviews/round-1'], $crawler->filter('.finding-back a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testAnUnknownFindingIsNotFound(): void
    {
        $this->givenAFindingToFix();

        $this->browser->request('GET', '/reviews/round-1/findings/F9');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAFindingOfAnUnknownRoundIsNotFound(): void
    {
        $this->givenAFindingToFix();

        $this->browser->request('GET', '/reviews/round-9/findings/F1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIdMergedIntoAnotherFindingIsNotFound(): void
    {
        $this->givenTwoReportsJudgedAsOneEntry(['F2' => 'P2']);

        $this->browser->request('GET', '/reviews/round-1/findings/F2');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIdTheQuoteCheckDroppedIsNotFound(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'The total is off by one.', 'none of its quoted code fragments is in the change');

        $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAFindingOfARoundNotJudgedYetIsNotFound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');

        $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertResponseStatusCodeSame(404);
    }

    private function givenAFindingToFix(): void
    {
        $this->givenAChangesRound();
        $this->ledger->reportFindings('round-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('round-1', 'security', []);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one. It drops the last item.');
    }

    /**
     * @param array<string, string> $second
     */
    private function givenTwoReportsJudgedAsOneEntry(array $second): void
    {
        $this->givenAChangesRound();
        $this->ledger->reportRatedFindings('round-1', 'correctness', ['F1' => 'P1']);
        $this->ledger->reportRatedFindings('round-1', 'security', $second);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');
    }

    private function givenAChangesRound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
    }
}
