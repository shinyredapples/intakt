<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class LedgerReviewPagesTest extends TestCase
{
    public function testTheRoundsOfOneChangeAreOneCardOnTheBoard(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->judgeRound('r1');
        $ledger->startRoundOf('feat/a', 'r2', 2);

        $cards = $this->pagesOn($ledger)->board()->cardsIn(BoardColumn::InReview);

        self::assertSame([['r1', 'r2']], array_map(static fn (Review $card): array => array_map(static fn (ReviewedRound $round): string => $round->round, $card->rounds), $cards));
    }

    public function testAJudgedRoundOfTheLedgerIsACardInDone(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->judgeRound('r1');

        $board = $this->pagesOn($ledger)->board();

        self::assertSame([1, 0], [$board->countOf(BoardColumn::Done), $board->countOf(BoardColumn::InReview)]);
    }

    public function testRoundsWithoutAChangeNameAreACardEach(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r1', 'alpha');
        $ledger->startRound('r2', 'alpha');

        self::assertSame(2, $this->pagesOn($ledger)->board()->countOf(BoardColumn::InReview));
    }

    public function testRoundsOfDifferentChangesAreSeparateCards(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->startRoundOf('feat/b', 'r2', 1);

        self::assertSame(2, $this->pagesOn($ledger)->board()->countOf(BoardColumn::InReview));
    }

    public function testAnEmptyLedgerGivesABoardWithNoCards(): void
    {
        $board = $this->pagesOn(new DashboardLedger())->board();

        self::assertSame(array_fill(0, 9, 0), array_map($board->countOf(...), BoardColumn::cases()));
    }

    public function testACardCarriesTheCostOfAllItsRounds(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->startRoundOf('feat/a', 'r2', 2);

        $card = $this->pagesOn($ledger)->board()->cardsIn(BoardColumn::InReview)[0];

        self::assertSame(1.0, $card->cost()->usd);
    }

    private function pagesOn(DashboardLedger $ledger): LedgerReviewPages
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));

        return new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords());
    }
}
