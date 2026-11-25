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

// Read from the markup, because the column map and the phone switcher must name the columns the board shows, in its order (#1460).
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
final class BoardColumnMapTest extends DashboardPageTestCase
{
    // The columns an install without tasks shows: the six Coming next names hold no card, so neither the map nor the switcher names them (#3154).
    private const array COLUMNS = ['Waiting on you', 'In review', 'Done'];

    // Hidden until assets/board-map.js measures a board wider than its space, so a board that fits, or a page without the script, shows no map.
    public function testTheColumnMapStartsHiddenWithOneSegmentPerShownColumnInBoardOrder(): void
    {
        $map = $this->browser->request('GET', '/')->filter('.stagemap');

        self::assertNotNull($map->attr('hidden'));
        self::assertSame(self::COLUMNS, $map->filter('button.sm-seg .sr-only')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(
            array_map(static fn (string $name): string => 'col-'.BoardColumn::from($name)->slug(), self::COLUMNS),
            $map->filter('button.sm-seg')->each(static fn (Crawler $segment): string => (string) $segment->attr('aria-controls')),
        );
    }

    public function testThePhoneSwitcherListsEveryShownColumnWithItsTrueTotalAndPressesWaitingOnYouFirst(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('43', 'round-2', 1);

        $switcher = $this->browser->request('GET', '/')->filter('nav.colswitch[aria-label="Board columns"]');

        $buttons = $switcher->filter('button');
        self::assertSame(self::COLUMNS, $buttons->each(static fn (Crawler $button): string => (string) $button->attr('data-column')));
        self::assertSame(['0', '1', '1'], $buttons->filter('.count')->each(static fn (Crawler $count): string => $count->text()));
        self::assertSame(['true', 'false', 'false'], $buttons->each(static fn (Crawler $button): string => (string) $button->attr('aria-pressed')));
        self::assertSame('col-in-review', $buttons->eq(1)->attr('aria-controls'));
    }

    // A speech user says the words they see, so the switcher's name is them, with the unit in hidden words after the count.
    public function testASwitcherButtonIsNamedByItsVisibleWordsAndCount(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $button = $this->browser->request('GET', '/')->filter('nav.colswitch button')->eq(1);

        self::assertSame('In review 1 task', $button->text());
    }

    // A jump moves the focus to the column, so the next Tab reaches the column's first card or strip instead of the first column's.
    public function testEveryColumnCanTakeTheFocusAJumpGivesItWithoutBeingATabStop(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(array_fill(0, count(self::COLUMNS), '-1'), $page->filter('.board > section.col')->each(static fn (Crawler $column): string => (string) $column->attr('tabindex')));
    }

    // Hide sits in the head above the cards, so after a jump to an open Done the next Tab reaches Hide and the one after it the first card, in the order the reader sees; the strip is left out because the stylesheet hides it on an open column. It reads the markup order only, because a test without a browser cannot press Tab; the keyboard walk-through of #1460 (E3) shows the real Tab order.
    public function testAfterAJumpToAnOpenDoneTheNextTabReachesHideAndThenItsFirstCard(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $done = $this->browser->request('GET', '/')->filter('.board > section#col-done');

        self::assertStringNotContainsString('collapsed', (string) $done->attr('class'));
        $stops = $done->filter('a[href], button:not(.col-fold), summary, [tabindex="0"]');
        self::assertGreaterThanOrEqual(2, $stops->count());
        self::assertSame('Hide', $stops->eq(0)->text());
        self::assertNotNull($stops->eq(1)->closest('.col-cards'));
    }
}
