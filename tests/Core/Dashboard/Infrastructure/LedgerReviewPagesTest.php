<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Dashboard\UseCase\ReviewBoundLedger;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(LedgerRounds::class)]
#[CoversClass(ReplayMarks::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
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

    public function testAReviewIsReadFromItsStartsAndTheRowsThatNameItsRoundsOrTheirCalls(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/b', 'other', 1);
        $ledger->startReviewer('other', 'security');
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->startReviewer('r1', 'correctness');
        $ledger->answer('r1', 'correctness', 0);
        $ledger->useTokens('r1', 1000, 200);
        $ledger->judgeFindings('r1', []);
        $ledger->startRoundOf('feat/a', 'r2', 2);

        $review = $this->pagesOn(new ReviewBoundLedger($ledger))->reviewOf('r2');

        self::assertNotNull($review);
        self::assertSame(['r1', 'r2'], array_map(static fn (ReviewedRound $round): string => $round->round, $review->rounds));
        self::assertSame(1200, $review->rounds[0]->tokens->total());
        self::assertCount(1, $review->rounds[0]->outcomes);
        self::assertTrue($review->rounds[0]->isJudged());
    }

    public function testATokenRowNamingBothItsRoundAndItsCallIsCountedOnce(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->useTokensSettledNamingTheRound('r1', 1000, 200);

        $review = $this->pagesOn(new ReviewBoundLedger($ledger))->reviewOf('r1');

        self::assertSame(1200, $review?->rounds[0]->tokens->total());
    }

    public function testAReviewOfARoundTheLedgerNeverStartedIsNotRead(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->reviewOf('r9'));
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

    public function testRoundsWhoseIdsAreDigitsAreCardsOnTheBoard(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', '42', 1);
        $ledger->startRound('7', 'alpha');

        self::assertSame(2, $this->pagesOn($ledger)->board()->countOf(BoardColumn::InReview));
    }

    public function testTheRoundsOfOneChangeWithDigitIdsAreOneCard(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', '42', 1);
        $ledger->startRoundOf('feat/a', '43', 2);

        self::assertSame(1, $this->pagesOn($ledger)->board()->countOf(BoardColumn::InReview));
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

    public function testACardOfAProjectFlaggedAsReplayCarriesTheReplayMark(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);

        $card = $this->pagesOn($ledger, ['alpha'])->board()->cardsIn(BoardColumn::InReview)[0];

        self::assertSame(ReplayMark::Replay, $card->latest()->mark);
    }

    public function testACardOfAProjectNeverFlaggedCarriesTheLiveMark(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);

        $card = $this->pagesOn($ledger, ['beta'])->board()->cardsIn(BoardColumn::InReview)[0];

        self::assertSame(ReplayMark::Live, $card->latest()->mark);
    }

    public function testEveryRoundUnknownToReviewTakesTheReplayMarkOfItsProject(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->judgeRound('r1');
        $ledger->startRoundOf('feat/a', 'r2', 2);

        self::assertSame([ReplayMark::Replay, ReplayMark::Replay], $this->marksOf($this->pagesOn($ledger, ['alpha'])->reviewOf('r1')));
    }

    public function testARoundStoredLiveStaysLiveAfterItsProjectWasFlagged(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);

        $card = $this->pagesOn($ledger, ['alpha'], ['r1' => ReplayMark::Live])->board()->cardsIn(BoardColumn::InReview)[0];

        self::assertSame(ReplayMark::Live, $card->latest()->mark);
    }

    public function testAReplayRoundOpenedAfterALiveRoundOfTheSameChangeIsMarkedAsAReplay(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->judgeRound('r1');
        $ledger->startRoundOf('feat/a', 'r2', 2);

        $review = $this->pagesOn($ledger, ['alpha'], ['r1' => ReplayMark::Live, 'r2' => ReplayMark::Replay])->reviewOf('r2');

        self::assertSame([[ReplayMark::Live, ReplayMark::Replay], false], [$this->marksOf($review), $review?->everyRoundIsAReplay()]);
    }

    public function testAReviewWhoseRoundsAreAllStoredAsReplayIsAReplayThroughout(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->judgeRound('r1');
        $ledger->startRoundOf('feat/a', 'r2', 2);

        self::assertTrue($this->pagesOn($ledger, [], ['r1' => ReplayMark::Replay, 'r2' => ReplayMark::Replay])->reviewOf('r1')?->everyRoundIsAReplay());
    }

    public function testAStartedReviewerIsReadFromTheRowsThatNameItsRoundAlone(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewer('other', 'correctness');
        $ledger->startReviewerOn('r1', 'correctness', 'claude-test');

        $started = $this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r1-correctness');

        self::assertSame(['correctness', 'claude-test'], [$started?->role, $started?->model]);
    }

    public function testAReviewerStartedInAnotherRoundIsNotStartedInThisOne(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewer('other', 'correctness');

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'other-correctness'));
    }

    public function testAStartInARoundWhoseIdContainsThisRoundsIdIsNotAStartInThisRound(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewer('r10', 'correctness');

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r10-correctness'));
    }

    public function testTheLatestStartOfAReviewerInARoundWins(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewerOn('r1', 'correctness', 'first-model');
        $ledger->startReviewerOn('r1', 'correctness', 'second-model');

        self::assertSame('second-model', $this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r1-correctness')?->model);
    }

    public function testTheLatestStartAfterARetryWinsWithAStartOfAnotherRoundBetween(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewerOn('r1', 'correctness', 'first-model');
        $ledger->startReviewerOn('r10', 'correctness', 'unrelated-model');
        $ledger->startReviewerOn('r1', 'correctness', 'latest-model');

        self::assertSame('latest-model', $this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r1-correctness')?->model);
    }

    public function testARoundIdThatIsAPrefixOfTheStoredRoundFindsNoStartedReviewer(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewer('r1', 'correctness');

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r', 'r1-correctness'));
    }

    public function testARowThatNamesTheReviewerButIsNoStartIsNotAStartedReviewer(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/a', 'r1', 1);
        $ledger->answer('r1', 'correctness', 0);

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r1-correctness'));
    }

    public function testAnotherReviewerStartedInTheRoundIsNotThisReviewer(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startReviewer('r1', 'security');

        self::assertNull($this->pagesOn(new ReviewBoundLedger($ledger))->startedReviewerOf('r1', 'r1-correctness'));
    }

    /**
     * @return list<?ReplayMark>
     */
    private function marksOf(?Review $review): array
    {
        return array_map(static fn (ReviewedRound $round): ?ReplayMark => $round->mark, $review->rounds ?? []);
    }

    /**
     * @param list<string>              $replays
     * @param array<string, ReplayMark> $stored
     */
    private function pagesOn(Ledger $ledger, array $replays = [], array $stored = []): LedgerReviewPages
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));

        return new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus($replays, $stored)));
    }
}
