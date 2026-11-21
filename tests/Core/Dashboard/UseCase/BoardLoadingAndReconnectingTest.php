<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

// Read from the rendered page, because the loading and reconnecting states are the board's own markup and the shell's, not demos (#1461, D5).
#[CoversClass(ShowBoardController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(Lane::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(Headline::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(FailingMains::class)]
#[UsesClass(FailingMain::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(SampleChangeSource::class)]
final class BoardLoadingAndReconnectingTest extends DashboardPageTestCase
{
    public function testEveryPageCarriesTheBoardSkeletonForAVisitToTheBoard(): void
    {
        $skeleton = $this->skeletonOn('/reviews');

        self::assertSame(
            ['Waiting on you', 'Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'In review', 'Merging', 'Done'],
            $skeleton->filter('.board > .col .col-head h3')->each(static fn (Crawler $name): string => $name->text()),
        );
        self::assertSame(['true'], array_values(array_unique($skeleton->filter('.board > .col')->each(static fn (Crawler $column): string => (string) $column->attr('aria-hidden')))));
        self::assertCount(14, $skeleton->filter('.skel-card'));
        self::assertCount(0, $skeleton->filter('a, button, [tabindex]'));
    }

    // Blank, because a count the skeleton made up would be read as the board's.
    public function testTheSkeletonShowsNoCountAndFoldsBacklogAndDoneLikeTheBoard(): void
    {
        $skeleton = $this->skeletonOn('/');

        self::assertSame([], array_filter($skeleton->filter('.count')->each(static fn (Crawler $count): string => trim($count->text(), " \u{a0}")), static fn (string $count): bool => '' !== $count));
        self::assertSame(['Backlog', 'Done'], $skeleton->filter('.col.collapsed .col-fold > span:first-child')->each(static fn (Crawler $name): string => $name->text()));
    }

    // The shell's connection state applies to the board, so a dropped hub shows the reconnecting line and the Offline marker there too.
    public function testTheBoardCarriesTheShellsReconnectingLineAndOfflineMarkerHidden(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertNotNull($page->filter('[data-connection-target="line"]')->attr('hidden'));
        self::assertNotNull($page->filter('[data-connection-target="marker"]')->attr('hidden'));
    }

    private function skeletonOn(string $path): Crawler
    {
        $this->browser->request('GET', $path);
        $html = $this->browser->getInternalResponse()->getContent();
        self::assertSame(1, preg_match('~<script type="text/html" id="board-skeleton" data-path="/">(.*?)</script>~s', $html, $template));

        return new Crawler('<div>'.($template[1] ?? '').'</div>');
    }
}
