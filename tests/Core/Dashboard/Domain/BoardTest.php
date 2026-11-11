<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Board::class)]
#[CoversClass(BoardColumn::class)]
#[CoversClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class BoardTest extends TestCase
{
    public function testTheBoardHasTheNineColumnsOfTheDesignInTheirOrder(): void
    {
        $names = array_map(static fn (BoardColumn $column): string => $column->value, BoardColumn::cases());

        self::assertSame(['Waiting on you', 'Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'In review', 'Merging', 'Done'], $names);
    }

    public function testAReviewWhoseLatestRoundRunsIsACardInInReview(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)])]);

        self::assertSame(['r1'], $this->roundsIn($board, BoardColumn::InReview));
    }

    public function testAReviewWhoseLatestRoundIsJudgedIsACardInDone(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([])])]);

        self::assertSame(['r1'], $this->roundsIn($board, BoardColumn::Done));
    }

    public function testAReviewWithAJudgedRoundAndANewRunningOneIsInInReviewOnly(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)->judgedWith([]), $this->round('r2', 'feat/a', 2)])]);

        self::assertSame([['r2'], []], [$this->roundsIn($board, BoardColumn::InReview), $this->roundsIn($board, BoardColumn::Done)]);
    }

    public function testEveryColumnWithoutATaskSourceHoldsNothing(): void
    {
        $board = new Board([new Review([$this->round('r1', 'feat/a', 1)]), new Review([$this->round('r2', 'feat/b', 1)->judgedWith([])])]);

        $others = array_filter(BoardColumn::cases(), static fn (BoardColumn $column): bool => !in_array($column, [BoardColumn::InReview, BoardColumn::Done], true));

        self::assertSame(array_fill(0, 7, 0), array_values(array_map($board->countOf(...), $others)));
    }

    public function testAColumnCountsEveryCardOfEveryProject(): void
    {
        $board = new Board([
            new Review([$this->round('r1', 'feat/a', 1)]),
            new Review([$this->started(new RoundStart('r2', 'beta', 2), new RoundPlace('feat/b', 1), $this->morning())]),
            new Review([$this->started(new RoundStart('r3', 'gamma', 2), new RoundPlace('feat/c', 1), $this->morning())]),
        ]);

        self::assertSame(3, $board->countOf(BoardColumn::InReview));
    }

    public function testACardCarriesItsProject(): void
    {
        $board = new Board([new Review([$this->started(new RoundStart('r1', 'beta', 2), new RoundPlace('feat/a', 1), $this->morning())])]);

        self::assertSame('beta', $board->cardsIn(BoardColumn::InReview)[0]->project);
    }

    public function testACardShowsItsLatestRoundForItsNumberReviewersAndLink(): void
    {
        $card = new Review([$this->round('r1', 'feat/a', 1), $this->started(new RoundStart('r2', 'alpha', 3), new RoundPlace('feat/a', 2), $this->morning())]);

        self::assertSame(['r2', 2, 3], [$card->latest()->round, $card->latest()->number, $card->latest()->reviewers]);
    }

    public function testCardsInAColumnShowTheNewestLatestRoundFirst(): void
    {
        $board = new Board([
            new Review([$this->started(new RoundStart('r1', 'alpha', 2), new RoundPlace('feat/a', 1), new DateTimeImmutable('2026-11-01T10:00:00+00:00'))]),
            new Review([$this->started(new RoundStart('r2', 'alpha', 2), new RoundPlace('feat/b', 1), new DateTimeImmutable('2026-11-03T10:00:00+00:00'))]),
            new Review([$this->started(new RoundStart('r3', 'alpha', 2), new RoundPlace('feat/c', 1), new DateTimeImmutable('2026-11-02T10:00:00+00:00'))]),
        ]);

        self::assertSame(['r2', 'r3', 'r1'], $this->roundsIn($board, BoardColumn::InReview));
    }

    /**
     * @return list<string>
     */
    private function roundsIn(Board $board, BoardColumn $column): array
    {
        return array_map(static fn (Review $card): string => $card->latest()->round, $board->cardsIn($column));
    }

    private function round(string $round, string $change, int $number): ReviewedRound
    {
        return $this->started(new RoundStart($round, 'alpha', 2), new RoundPlace($change, $number), $this->morning());
    }

    private function started(RoundStart $start, RoundPlace $place, DateTimeImmutable $startedAt): ReviewedRound
    {
        return new ReviewedRound($start, $place, $startedAt, null);
    }

    private function morning(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01T10:00:00+00:00');
    }
}
