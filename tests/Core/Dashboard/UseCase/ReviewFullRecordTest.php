<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

// The owner decision for #3360: the main view keeps one message, and the timeline, reviewers, tokens and cost move to the Full record, so the findings never show twice on one screen.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(FindingUrgency::class)]
final class ReviewFullRecordTest extends DashboardPageTestCase
{
    public function testTheMainViewRendersNoneOfTheMovedSectionsAndLinksToTheFullRecordOnce(): void
    {
        $this->givenAChangesRoundWithOneFindingToFix();

        $main = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $main->filter('#timeline-title, .round, .finding-card, table, .page-head .meta, #review-summary-tokens, #review-summary-cost, .ttabs'));
        self::assertSame(['/reviews/round-1/record'], $main->filter('a.full-record')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testTheFullRecordRendersTheTimelineTheReviewersTheTokensAndTheCost(): void
    {
        $this->givenAChangesRoundWithOneFindingToFix();

        $record = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Timeline', $record->filter('#timeline-title')->text());
        self::assertCount(1, $record->filter('#review-summary-tokens'));
        self::assertCount(1, $record->filter('#review-summary-cost'));
        self::assertGreaterThan(0, $record->filter('#round-round-1 table')->count());
        self::assertSame('/reviews/round-1', $record->filter('.record-back a')->attr('href'));
    }

    public function testTheFindingsShowOnceOnEachView(): void
    {
        $this->givenAChangesRoundWithOneFindingToFix();

        $main = $this->browser->request('GET', '/reviews/round-1');
        $record = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([1, 0], [$main->filter('.fix-row')->count(), $main->filter('.finding-card')->count()]);
        self::assertSame([0, 1, 0], [$record->filter('.fix-row')->count(), $record->filter('.finding-card')->count(), $record->filter('.verdict-card')->count()]);
    }

    public function testWhileAPassIsInReviewTheMainViewKeepsTheNowBlockAndTheFullRecordTheLiveLog(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $main = $this->browser->request('GET', '/reviews/round-1');
        $record = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([1, 0], [$main->filter('.state-block')->count(), $main->filter('#live-log-pane')->count()]);
        self::assertSame([0, 1, 1], [$record->filter('.state-block')->count(), $record->filter('#live-log-pane')->count(), $record->filter('#timeline-pane')->count()]);
    }

    // A pass that ended without a verdict keeps the earlier judged pass's findings to fix, and that pass is not the newest, which alone opens on arrival.
    public function testAFindingLinkIntoAPassThatIsNotTheNewestFindsThatPassOpen(): void
    {
        $this->givenAChangesRoundWithOneFindingToFix();
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);
        $link = (string) $this->browser->request('GET', '/reviews/round-2')->filter('.to-fix a.fix-row')->attr('href');
        self::assertSame('/reviews/round-1/record#round-round-1-finding-F1', $link);

        $record = $this->browser->request('GET', $link);

        self::assertCount(1, $record->filter('#round-round-1 > details.ev[open] li.finding-card#round-round-1-finding-F1'));
    }

    public function testAFullRecordOpenedByTheNewestPassKeepsAnOlderJudgedPassFolded(): void
    {
        $this->givenAChangesRoundWithOneFindingToFix();
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $record = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([1, 0], [$record->filter('#round-round-1 > details.ev')->count(), $record->filter('#round-round-1 > details.ev[open]')->count()]);
    }

    public function testAnUnknownRoundHasNoFullRecord(): void
    {
        $this->browser->request('GET', '/reviews/round-9/record');

        self::assertResponseStatusCodeSame(404);
    }

    // Kept across the two views' requests, so the second one reads the same ledger.
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser->disableReboot();
    }

    private function givenAChangesRoundWithOneFindingToFix(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);
    }
}
