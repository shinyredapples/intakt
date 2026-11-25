<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\InfrastructureFigure;
use Intakt\Core\Dashboard\Domain\InfrastructureRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

// On the page test case, because the layout around the board reads the ledger, which these tests answer from memory.
#[CoversClass(Board::class)]
#[UsesClass(InfrastructureRow::class)]
#[UsesClass(InfrastructureFigure::class)]
final class BoardComingNextTemplateTest extends DashboardPageTestCase
{
    // No install can put a card in a folded column yet (#3154, decision 1), so the board's own template is handed the lists a board with a Building task would give it.
    public function testAFoldedColumnThatHoldsATaskRendersInItsPlaceAndLeavesTheComingNextLine(): void
    {
        $html = $this->board(
            [BoardColumn::WaitingOnYou, BoardColumn::Building, BoardColumn::InReview, BoardColumn::Done],
            [BoardColumn::Backlog, BoardColumn::Ready, BoardColumn::Planned, BoardColumn::Checking, BoardColumn::Merging],
        );

        $waiting = strpos($html, 'id="col-waiting-on-you"');
        $building = strpos($html, 'id="col-building"');
        $review = strpos($html, 'id="col-in-review"');
        self::assertIsInt($waiting);
        self::assertIsInt($building);
        self::assertIsInt($review);
        self::assertTrue($waiting < $building && $building < $review, 'Building renders between Waiting on you and In review.');
        self::assertStringContainsString('<span>Backlog, Ready, Planned, Checking and Merging show here once a task is in one of them</span>', $html);
        self::assertStringNotContainsString('Building show', $html);
        self::assertStringNotContainsString('Building,', $html);
    }

    // No install can put a card in Planned yet (#3155, decision 2), so the template is handed the lists a board with a Planned task would give it; the count is the empty board's own, and the test pins the column's place, head and count.
    public function testAShownPlannedColumnRendersInItsPlaceWithItsHeadAndCountAndLeavesTheComingNextLine(): void
    {
        $html = $this->board(
            [BoardColumn::WaitingOnYou, BoardColumn::Planned, BoardColumn::InReview, BoardColumn::Done],
            [BoardColumn::Backlog, BoardColumn::Ready, BoardColumn::Building, BoardColumn::Checking, BoardColumn::Merging],
        );

        $waiting = strpos($html, 'id="col-waiting-on-you"');
        $planned = strpos($html, '<section class="col" id="col-planned" aria-label="Planned" tabindex="-1">');
        $review = strpos($html, 'id="col-in-review"');
        self::assertIsInt($waiting);
        self::assertIsInt($planned);
        self::assertIsInt($review);
        self::assertTrue($waiting < $planned && $planned < $review, 'Planned renders between Waiting on you and In review.');
        self::assertMatchesRegularExpression('~id="col-planned"[^>]*>\s*<div class="col-head"><h3>Planned</h3><span class="count[^"]*"[^>]*>0</span>~', $html);
        self::assertStringContainsString('<span>Backlog, Ready, Building, Checking and Merging show here once a task is in one of them</span>', $html);
        self::assertStringNotContainsString('Planned,', $html);
        self::assertStringNotContainsString('Planned show', $html);
    }

    public function testTheComingNextLineUsesTheSingularWordingForOneFoldedColumn(): void
    {
        $shown = array_values(array_filter(BoardColumn::cases(), static fn (BoardColumn $column): bool => BoardColumn::Building !== $column));

        $html = $this->board($shown, [BoardColumn::Building]);

        self::assertStringContainsString('<span>Building shows here once it holds a task</span>', $html);
    }

    /**
     * @param list<BoardColumn> $columns
     * @param list<BoardColumn> $comingNext
     */
    private function board(array $columns, array $comingNext): string
    {
        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/?board=1'));
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $board = new Board(new DateTimeImmutable('2026-11-01T10:30:00+00:00'), []);

        return $twig->render('dashboard/board.html.twig', [
            'board' => $board,
            'failing' => [],
            'failing_mains' => [],
            'working' => $board->whoIsWorking(),
            'waiting' => [],
            'columns' => $columns,
            'coming_next' => $comingNext,
            'infrastructure' => InfrastructureRow::refused('not read in this test'),
        ]);
    }
}
