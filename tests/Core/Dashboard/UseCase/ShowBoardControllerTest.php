<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ShowBoardController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RefuseForeignHost::class)]
final class ShowBoardControllerTest extends DashboardPageTestCase
{
    public function testTheRootAddressShowsTheBoard(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Board', $page->filter('.page-head h1')->text());
    }

    public function testTheBoardShowsTheNineColumnsInTheOrderOfTheDesign(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(
            ['Waiting on you', 'Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'In review', 'Merging', 'Done'],
            $page->filter('.board > section.col .col-head h3')->each(static fn (Crawler $name): string => $name->text()),
        );
    }

    public function testAReviewWhoseLatestRoundRunsIsACardInInReview(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'In review'));
        self::assertSame([], $this->cardNumbersIn($page, 'Done'));
    }

    public function testAReviewWhoseLatestRoundIsJudgedIsACardInDone(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'Done'));
        self::assertSame([], $this->cardNumbersIn($page, 'In review'));
    }

    public function testAChangeReviewedInSeveralRoundsIsOneCard(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'In review'));
        self::assertSame([], $this->cardNumbersIn($page, 'Done'));
    }

    public function testEveryColumnWithoutACardSaysNothingHere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(
            ['Waiting on you', 'Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'Merging', 'Done'],
            $page->filter('.board > section.col')->reduce(static fn (Crawler $column): bool => 1 === $column->filter('.col-empty')->count())->each(static fn (Crawler $column): string => $column->filter('h3')->text()),
        );
        self::assertSame(['Nothing here'], array_values(array_unique($page->filter('.col-empty')->each(static fn (Crawler $empty): string => $empty->text()))));
    }

    public function testEachColumnCountsEveryCardOfEveryProject(): void
    {
        $this->ledger->startRound('round-alpha', 'alpha');
        $this->ledger->startRound('round-beta', 'beta');

        $page = $this->browser->request('GET', '/');

        self::assertSame('2', $this->column($page, 'In review')->filter('.col-head .count')->text());
        self::assertSame('0', $this->column($page, 'Done')->filter('.col-head .count')->text());
    }

    public function testACardCarriesItsProjectForTheFilterAndOnTheCard(): void
    {
        $this->ledger->startRound('round-1', 'beta');

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('[data-project]');

        self::assertSame('beta', $card->attr('data-project'));
        self::assertSame('beta', $card->filter('.card .pname')->text());
    }

    public function testACardLinksToTheReviewOfItsLatestRound(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('a.card');

        self::assertSame('/reviews/round-2', $card->attr('href'));
        self::assertSame('Review of 42', $card->filter('.title')->text());
    }

    public function testACardShowsItsRoundItsReviewersAndItsCost(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $facts = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card .meta > span');

        self::assertCount(3, $facts);
        self::assertSame('Round 2', $this->wordsOf($facts->eq(0)->filter('.term')));
        self::assertSame(['2 reviewers', 'Cost '.Cost::none()->reading()], [$facts->eq(1)->text(), $facts->eq(2)->text()]);
    }

    public function testTheRoundOnACardExplainsTheWord(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $term = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card .meta .term');

        self::assertSame('round', $term->attr('data-term'));
        self::assertSame('One build, its checks and its review. Sending work back starts the next round.', $term->filter('.term-tip')->text());
    }

    public function testACardOfARoundStartedBeforeTheLedgerNamedItsChangeShowsTheRound(): void
    {
        $this->ledger->startRound('round-7', 'alpha');

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card');

        self::assertSame('#round-7', $card->filter('.top span')->first()->text());
        self::assertSame('Review of round round-7', $card->filter('.title')->text());
        self::assertSame('Round 1', $this->wordsOf($card->filter('.meta .term')));
    }

    public function testTheBoardLinksToTheReviewListBehindIt(): void
    {
        $link = $this->browser->request('GET', '/')->filter('.page-head .actions a');

        self::assertSame('/reviews', $link->attr('href'));
        self::assertSame('Open the review list', $link->text());
    }

    public function testTheBoardUsesTheFullWidthOfThePage(): void
    {
        self::assertCount(1, $this->browser->request('GET', '/')->filter('main.content.wide'));
    }

    public function testTheRootAddressRefusesAHostThatDoesNotNameTheDashboard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_HOST' => 'attacker.example:7780']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    private function column(Crawler $page, string $name): Crawler
    {
        return $page->filter('.board > section.col')->reduce(static fn (Crawler $column): bool => $column->filter('.col-head h3')->text() === $name);
    }

    // The words a term shows, without its explanation, which sits inside it for hover and focus.
    private function wordsOf(Crawler $term): string
    {
        return trim(str_replace($term->filter('.term-tip')->text(), '', $term->text()));
    }

    /**
     * @return list<string>
     */
    private function cardNumbersIn(Crawler $page, string $name): array
    {
        return array_values($this->column($page, $name)->filter('.col-cards .card .top span:first-child')->each(static fn (Crawler $number): string => $number->text()));
    }
}
