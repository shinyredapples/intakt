<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ListReviewsController::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
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

    public function testTheProjectParameterChoosesThatProjectInTheTopBarFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertSame(['alpha'], $crawler->filter('#projfilter option[selected]')->each(static fn ($option): string => $option->attr('value')));
    }

    public function testTheTopBarFilterCanSwitchToAnotherProjectAfterFollowingAProjectLink(): void
    {
        $this->ledger->startRound('round-alpha', 'alpha');
        $this->ledger->startRound('round-beta', 'beta');

        $crawler = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertCount(1, $crawler->filter('#projfilter option[value="beta"]'));
        self::assertCount(1, $crawler->filter('tr[data-project="beta"]'));
    }

    public function testAProjectParameterTheInstallDoesNotListLeavesTheFilterOnAllProjects(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews?project=gone');

        self::assertCount(0, $crawler->filter('#projfilter option[selected]'), 'Without a selected option the browser shows the first one, All projects.');
        self::assertSame(['round-1'], $crawler->filter('tbody tr td:first-child')->each(static fn ($cell): string => $cell->text()));
    }

    public function testTheReviewListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/reviews');

        self::assertSelectorTextContains('main', 'No review has started yet.');
        self::assertSelectorNotExists('table');
        self::assertSelectorNotExists('.failing-heads');
    }

    public function testAHandedOverHeadShowsItsCauseAndNextTryOnItsCard(): void
    {
        $this->ledger->handOverHead('alpha', '7', '<b>');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('alpha, pull request 7', $crawler->filter('.failing-heads .card h3')->text());
        self::assertSame('handed over to the maintainer agent, 4 failures in a row, next try 2026-10-28 12:08:00 UTC', $crawler->filter('.failing-heads .card .state')->text());
        self::assertSame('RuntimeException: commit <b> not fetched', $crawler->filter('.failing-heads .card .cause')->text());
    }

    public function testTheProjectParameterKeepsTheFailedHeadsOfEveryProjectForTheTopBarFilter(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $this->ledger->failHead('beta', '8', 1);

        $crawler = $this->browser->request('GET', '/reviews?project=beta');

        self::assertSame(['alpha', 'beta'], $crawler->filter('.failing-heads .card')->each(static fn ($card): string => $card->attr('data-project')));
    }
}
