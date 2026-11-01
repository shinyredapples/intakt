<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ListReviewsController::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
final class ListReviewsControllerTest extends DashboardPageTestCase
{
    public function testTheReviewListShowsEachRoundWithItsProjectNewestFirst(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
        self::assertSame(['round-2', 'round-1'], $crawler->filter('tbody tr td:first-child')->each(static fn ($cell): string => $cell->text()));
        self::assertSame('beta', $crawler->filter('tbody tr:first-child td:nth-child(2)')->text());
        self::assertSame('2', $crawler->filter('tbody tr:first-child td:nth-child(3)')->text());
        self::assertSame('2026-10-28 12:00:00', $crawler->filter('tbody tr:first-child td:nth-child(4)')->text());
    }

    public function testARoundWithAJudgeDecisionIsListedAsJudged(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('judged', $crawler->filter('tbody tr td:nth-child(5)')->text());
    }

    public function testARoundWithoutAJudgeDecisionIsListedAsInProgress(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('in progress', $crawler->filter('tbody tr td:nth-child(5)')->text());
    }

    public function testTheProjectParameterKeepsOnlyThatProjectsRounds(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertSame(['round-1'], $crawler->filter('tbody tr td:first-child')->each(static fn ($cell): string => $cell->text()));
        self::assertSelectorTextContains('h1', 'Reviews of alpha');
    }

    public function testTheReviewListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/reviews');

        self::assertSelectorTextContains('main', 'No review has started yet.');
        self::assertSelectorNotExists('table');
    }
}
