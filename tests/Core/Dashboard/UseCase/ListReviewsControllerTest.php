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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    public function testTheTableOfRoundsScrollsInANamedRegionTheKeyboardReaches(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Review rounds', $crawler->filter('.table-scroll[tabindex="0"][role="region"]')->last()->attr('aria-label'));
    }

    public function testARoundWithAJudgeDecisionIsListedAsJudged(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Judged', $crawler->filter('tbody tr td:nth-child(5) .chip.state-judged')->text());
    }

    public function testARoundWithoutAJudgeDecisionIsListedAsInProgress(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('In progress', $crawler->filter('tbody tr td:nth-child(5) .chip.state-running')->text());
    }

    public function testThePageHeadNamesThePageAndSaysWhatItLists(): void
    {
        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Reviews', $crawler->filter('.page-head h1')->text());
        self::assertSame('Every review round, newest first. Open a round to see its review.', $crawler->filter('.page-head .lead')->text());
    }

    public function testTheProjectParameterChoosesThatProjectInTheTopBarFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertSame(['alpha'], $crawler->filter('#projfilter option[selected]')->each(static fn ($option): ?string => $option->attr('value')));
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

    public function testAProjectLinkMarksOnlyTheProjectWithExactlyThatNameSelected(): void
    {
        $this->ledger->startRound('round-one', '1');
        $this->ledger->startRound('round-zero-one', '01');

        $crawler = $this->browser->request('GET', '/reviews?project=1');

        self::assertSame(['1'], $crawler->filter('#projfilter option[selected]')->each(static fn ($option): ?string => $option->attr('value')));
    }

    public function testAProjectLinkToProjectTenLeavesAProjectNamedInExponentNotationUnselected(): void
    {
        $this->ledger->startRound('round-ten', '10');
        $this->ledger->startRound('round-exponent', '1e1');

        $crawler = $this->browser->request('GET', '/reviews?project=10');

        self::assertSame(['10'], $crawler->filter('#projfilter option[selected]')->each(static fn ($option): ?string => $option->attr('value')));
    }

    public function testAProjectLinkWithADecimalSpellingOfAListedNameSelectsNoProject(): void
    {
        $this->ledger->startRound('round-one', '1');

        $crawler = $this->browser->request('GET', '/reviews?project=1.0');

        self::assertCount(0, $crawler->filter('#projfilter option[selected]'));
    }

    public function testAProjectLinkWithALeadingZeroSelectsNoProjectWhenOnlyTheNameWithoutItIsListed(): void
    {
        $this->ledger->startRound('round-one', '1');

        $crawler = $this->browser->request('GET', '/reviews?project=01');

        self::assertCount(0, $crawler->filter('#projfilter option[selected]'));
    }

    public function testAProjectParameterThatIsAnArrayIsRefusedWithoutRenderingThePage(): void
    {
        $this->browser->request('GET', '/reviews?project[]=alpha');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "Invalid query parameter "project"."', NotFoundHttpException::class));
    }

    public function testTheReviewListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/reviews');

        self::assertSelectorTextContains('main .empty', 'No review has started yet.');
        self::assertSelectorNotExists('table');
        self::assertSelectorNotExists('.failing-heads');
    }

    public function testAHandedOverHeadShowsItsCauseAndNextTryOnItsCard(): void
    {
        $this->ledger->handOverHead('alpha', '7', '<b>');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('alpha', $crawler->filter('.failing-heads .card .pname')->text());
        self::assertSame('Pull request 7: handed over to the maintainer agent', $crawler->filter('.failing-heads .card .title')->text());
        self::assertSame('RuntimeException: commit <b> not fetched', $crawler->filter('.failing-heads .card .blocker.broken')->text());
        self::assertSame(['4 failures in a row', 'Next try 2026-10-28 12:08:00 UTC'], $crawler->filter('.failing-heads .card .meta > span')->each(static fn ($fact): string => $fact->text()));
    }

    // The board's banners link here.
    public function testTheFailedHeadsSectionIsTheTargetOfItsOwnAnchor(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertCount(1, $crawler->filter('section#failing-heads.failing-heads'));
    }

    public function testAHeadThatIsNotHandedOverSaysItIsBackingOff(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Pull request 7: backing off', $crawler->filter('.failing-heads .card .title')->text());
        self::assertSame('1 failure in a row', $crawler->filter('.failing-heads .card .meta > span')->first()->text());
    }

    // A card that links nowhere would take the hover of one that opens a page.
    public function testAFailingHeadCardIsNoLink(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertCount(1, $crawler->filter('.failing-heads div.card'));
        self::assertCount(0, $crawler->filter('.failing-heads a'));
    }

    public function testTheProjectParameterKeepsTheFailedHeadsOfEveryProjectForTheTopBarFilter(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $this->ledger->failHead('beta', '8', 1);

        $crawler = $this->browser->request('GET', '/reviews?project=beta');

        self::assertSame(['alpha', 'beta'], $crawler->filter('.failing-heads [data-project]')->each(static fn ($card): ?string => $card->attr('data-project')));
    }
}
