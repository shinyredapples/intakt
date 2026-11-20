<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Domain\UpNextRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowTrends\ShowTrendsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ShowTrendsController::class)]
#[UsesClass(UpNext::class)]
#[UsesClass(UpNextRound::class)]
#[UsesClass(Board::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(SampleChangeSource::class)]
final class ShowTrendsUpNextTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    public function testWithNothingPlannedUpNextReadsNothingRunningOrQueued(): void
    {
        $page = $this->browser->request('GET', '/trends');

        self::assertSame('Nothing running or queued.', $page->filter('#trends-up-next p')->text());
    }

    public function testEachUpNextEntryLinksToItsReviewPage(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $page = $this->browser->request('GET', '/trends');

        $link = $page->filter('#trends-up-next li a');
        self::assertCount(1, $link);
        self::assertSame('/reviews/'.self::ROUND, $link->attr('href'));
        self::assertStringContainsString('running: security', $page->filter('#trends-up-next li')->text());
    }

    public function testTheFiguresSitUnderTheTargetNamedForHowTheTabCountsTheDemo(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->judgeRound(self::ROUND);

        $page = $this->browser->request('GET', '/trends');

        self::assertCount(1, $page->filter('#trends-figures-with-demo .tiles'));
    }
}
