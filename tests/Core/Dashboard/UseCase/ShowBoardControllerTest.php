<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Asset\Packages;
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
#[UsesClass(Lane::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
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

    public function testEveryFlowColumnWithoutACardSaysNothingHere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(
            ['Backlog', 'Ready', 'Planned', 'Building', 'Checking', 'Merging', 'Done'],
            $page->filter('.board > section.col:not(.col-needs)')->reduce(static fn (Crawler $column): bool => 1 === $column->filter('.col-empty')->count())->each(static fn (Crawler $column): string => $column->filter('h3')->text()),
        );
        self::assertSame(['Nothing here'], array_values(array_unique($page->filter('.col:not(.col-needs) .col-empty')->each(static fn (Crawler $empty): string => $empty->text()))));
    }

    public function testAnEmptyWaitingOnYouColumnSaysNothingNeedsYou(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $column = $this->column($this->browser->request('GET', '/'), 'Waiting on you');

        self::assertSame('Nothing needs you', $column->filter('.col-empty')->text());
    }

    public function testOnlyTheFirstColumnIsPinnedAsWaitingOnYou(): void
    {
        $pinned = $this->browser->request('GET', '/')->filter('.board > section.col.col-needs');

        self::assertCount(1, $pinned);
        self::assertSame('Waiting on you', $pinned->filter('.col-head h3')->text());
        self::assertSame('col-waiting-on-you', $this->browser->getCrawler()->filter('.board > section.col')->first()->attr('id'));
    }

    public function testAnEmptyWaitingOnYouColumnCountsZeroWithoutAmber(): void
    {
        $count = $this->column($this->browser->request('GET', '/'), 'Waiting on you')->filter('.col-head .count');

        self::assertSame('0', $count->text());
        self::assertStringNotContainsString('needs', (string) $count->attr('class'));
    }

    public function testOnlyBacklogAndDoneStartCollapsed(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(['col-backlog', 'col-done'], $page->filter('.board > section.col.collapsed')->each(static fn (Crawler $column): string => (string) $column->attr('id')));
        self::assertCount(2, $page->filter('.board .col-fold'));
        self::assertCount(2, $page->filter('.board .col-hide'));
    }

    public function testACollapsedColumnsStripShowsItsNameAndCountSideBySide(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $strip = $this->column($this->browser->request('GET', '/'), 'Done')->filter('button.col-fold');

        self::assertSame(['Show Done', '1', 'task'], $strip->children()->each(static fn (Crawler $part): string => $part->text()));
        self::assertSame('count', $strip->filter('.count')->attr('class'));
    }

    // A speech user says the words they see, so the name holds them instead of an aria-label that would replace them.
    public function testACollapsedColumnsStripIsNamedByItsVisibleWordsBetweenHiddenOnes(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        self::assertSame('Show Done1 task', $this->column($page, 'Done')->filter('button.col-fold')->text());
        self::assertSame('Show Backlog0 tasks', $this->column($page, 'Backlog')->filter('button.col-fold')->text());
        self::assertSame(['Show ', ' task'], $this->column($page, 'Done')->filter('button.col-fold .sr-only')->each(static fn (Crawler $hidden): string => $hidden->text(normalizeWhitespace: false)));
        self::assertNull($this->column($page, 'Done')->filter('button.col-fold')->attr('aria-label'));
    }

    public function testACollapsedColumnsStripSaysItIsCollapsedAndControlsTheColumnsCards(): void
    {
        $column = $this->column($this->browser->request('GET', '/'), 'Done');
        $strip = $column->filter('button.col-fold');

        self::assertSame(['false', 'cards-done'], [$strip->attr('aria-expanded'), $strip->attr('aria-controls')]);
        self::assertSame('cards-done', $column->filter('.col-cards')->attr('id'));
    }

    public function testACollapsedColumnOffersHideInItsHeadNamingTheColumn(): void
    {
        $hide = $this->column($this->browser->request('GET', '/'), 'Backlog')->filter('.col-head button.col-hide');

        self::assertSame(['Hide', 'Hide Backlog', 'button'], [$hide->text(), $hide->attr('aria-label'), $hide->attr('type')]);
    }

    public function testAnOpenColumnHasNoStripAndNoHide(): void
    {
        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertStringNotContainsString('collapsed', (string) $column->attr('class'));
        self::assertCount(0, $column->filter('.col-fold, .col-hide'));
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
        self::assertSame('Round 2', $this->wordsWithoutExplanationOf($facts->eq(0)->filter('.term')));
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
        self::assertSame('Round 1', $this->wordsWithoutExplanationOf($card->filter('.meta .term')));
    }

    public function testACrowdedColumnShowsTwelveCardsAndOffersTheOtherTwentyEight(): void
    {
        $this->startRoundsOf(40);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('12', $column->attr('data-page-limit'));
        self::assertCount(12, $column->filter('.col-cards > article:not([data-past-limit])'));
        self::assertCount(28, $column->filter('.col-cards > article[data-past-limit]'));
        self::assertSame(['Show 28 more', 'button'], [$column->filter('.col-cards > button.more-btn')->text(), $column->filter('.more-btn')->attr('type')]);
    }

    public function testACrowdedColumnCountsEveryCardNotOnlyTheShownOnes(): void
    {
        $this->startRoundsOf(40);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('40', $column->filter('.col-head .count')->text());
    }

    public function testAColumnOfThirteenCardsOffersOneMoreAfterTheFirstTwelveInTheBoardsOrder(): void
    {
        $this->startRoundsOf(13);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('Show 1 more', $column->filter('.more-btn')->text());
        self::assertSame(['#13'], $column->filter('[data-past-limit] .top span:first-child')->each(static fn (Crawler $number): string => $number->text()));
        self::assertSame('#12', $column->filter('article:not([data-past-limit]) .top span:first-child')->last()->text());
    }

    public function testAColumnOfTwelveCardsShowsThemAllWithoutShowMore(): void
    {
        $this->startRoundsOf(12);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertCount(12, $column->filter('.col-cards > article'));
        self::assertCount(0, $column->filter('.more-btn, [data-past-limit]'));
        self::assertNull($column->attr('data-page-limit'));
    }

    // DESIGN.md: a card clamps a long title to three lines and the corner truncates a long project name, so the page carries both whole for the clamp and the tooltip.
    public function testACardCarriesALongTitleAndALongProjectNameWhole(): void
    {
        $change = str_repeat('a-change-with-a-long-name-', 8);
        $project = 'a-project-with-a-name-longer-than-its-corner';
        $this->ledger->startRoundOf($change, 'round-1', 1);
        $this->ledger->startRound('round-2', $project);

        $cards = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card');

        self::assertSame('Review of '.$change, $cards->first()->filter('.title')->text());
        self::assertSame(['#'.$change, '#'.$change], [$cards->first()->filter('.top .num')->text(), $cards->first()->filter('.top .num')->attr('title')]);
        self::assertSame([$project, $project], [$cards->last()->filter('.pname')->text(), $cards->last()->filter('.pname')->attr('title')]);
    }

    public function testEachRunningReviewerIsALaneWithItsProjectChangeAndRound(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $lanes = $this->browser->request('GET', '/')->filter('.working .lane');

        self::assertCount(1, $lanes);
        self::assertSame('42', $lanes->filter('.what')->text());
        self::assertSame('Round 1', $lanes->filter('.meta span')->last()->text());
        self::assertStringStartsWith('correctness', $lanes->filter('.who')->text());
    }

    public function testAReviewerThatHasAnsweredIsNoLane(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 0);

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('.working .lane'));
    }

    public function testTheSummaryCountsTheRunningReviewersInWords(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');

        $summary = $this->browser->request('GET', '/')->filter('.working .w-sum');

        self::assertSame('2 working', $summary->text());
    }

    public function testABoardWithNobodyRunningSaysNobodyIsWorking(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame('Nobody is working yet', $page->filter('.working .w-sum')->text());
        self::assertCount(0, $page->filter('.working .lane'));
    }

    public function testTheSummaryIsAClosedDisclosureWithAChevronAboveTheColumns(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertCount(1, $page->filter('details.working:not([open]) > summary svg.chev'));
        self::assertCount(1, $page->filter('details.working + section .board'));
    }

    public function testTheBoardLinksToTheReviewListBehindIt(): void
    {
        $link = $this->browser->request('GET', '/')->filter('.page-head .actions a');

        self::assertSame('/reviews', $link->attr('href'));
        self::assertSame('Open the review list', $link->text());
    }

    public function testABoardWithoutFailingHeadsShowsNoBanner(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertCount(0, $this->browser->request('GET', '/')->filter('.banner'));
    }

    public function testEachFailingHeadIsABrokenBannerOfItsProjectAboveWhoIsWorking(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $this->ledger->failHead('beta', '8', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['alpha', 'beta'], $page->filter('.page-head ~ .banner.broken')->each(static fn (Crawler $banner): ?string => $banner->attr('data-project')));
        self::assertCount(1, $page->filter('.banner + details.working'));
        self::assertCount(0, $page->filter('details.working ~ .banner'));
    }

    public function testABackingOffHeadsBannerNamesItsPullRequestItsCauseAndItsNextTry(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $banner = $this->browser->request('GET', '/')->filter('.banner');

        self::assertSame('Pull request 7 of alpha failed', $banner->filter('.banner-toggle span')->text());
        self::assertSame('Pull request 7 of alpha failed. 1 failure in a row, next try at 12:08 UTC: RuntimeException: commit a not fetched', $banner->filter('p')->text());
    }

    public function testAHandedOverHeadsBannerSaysTheMaintainerAgentHasIt(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'c');

        $text = $this->browser->request('GET', '/')->filter('.banner p')->text();

        self::assertSame('Pull request 7 of alpha failed. Handed over to the maintainer agent after 4 failures: RuntimeException: commit c not fetched', $text);
    }

    public function testABannerLinksToTheFailedPullRequestsOnTheReviewList(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $link = $this->browser->request('GET', '/')->filter('.banner > a.btn');

        self::assertSame('/reviews#failing-heads', $link->attr('href'));
        self::assertSame('See the failed pull requests', $link->text());
    }

    public function testACredentialInTheCauseIsRedactedOnTheBanner(): void
    {
        $token = 'ghp_'.str_repeat('a1', 18);
        $this->ledger->failHeadBecause('alpha', '7', 'clone refused for token '.$token);

        $page = $this->browser->request('GET', '/');

        self::assertStringContainsString('clone refused for token [redacted]', $page->filter('.banner p')->text());
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    public function testMarkupInTheProjectAndTheCauseIsShownAsTextOnTheBanner(): void
    {
        $this->ledger->failHeadBecause('<i>alpha</i>', '7', '<b>exit 1</b>');

        $banner = $this->browser->request('GET', '/')->filter('.banner');

        self::assertSame('<i>alpha</i>', $banner->attr('data-project'));
        self::assertSame('Pull request 7 of <i>alpha</i> failed', $banner->filter('.banner-toggle span')->text());
        self::assertStringEndsWith('of <i>alpha</i> failed. 2 failures in a row, next try at 12:08 UTC: <b>exit 1</b>', $banner->filter('p')->text());
        self::assertCount(0, $banner->filter('i, b'));
    }

    public function testABoardWithoutLiveUpdatesWiresTheBannerToggleToTheBannerScript(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('script[type="importmap"]'));
        self::assertCount(0, $page->filter('.banner[data-controller], .banner [data-action]'));
        self::assertSame('false', $page->filter('.banner > button.banner-toggle')->attr('aria-expanded'));
        $script = $page->filter('head script[type="module"]')->text();
        self::assertStringContainsString(sprintf("import { toggleBanners } from '%s';", $packages->getUrl('banner-toggle.js')), $script);
        self::assertStringContainsString('toggleBanners(document);', $script);
    }

    public function testTheBannerScriptTheBoardImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('banner-toggle.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function toggleBanners(page)', $this->browser->getInternalResponse()->getContent());
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

    private function startRoundsOf(int $changes): void
    {
        foreach (range(1, $changes) as $change) {
            $this->ledger->startRoundOf((string) $change, 'round-'.$change, 1);
        }
    }

    private function wordsWithoutExplanationOf(Crawler $term): string
    {
        return trim(str_replace($term->filter('.term-tip')->text(), '', $term->text()));
    }

    /**
     * @return list<string>
     */
    private function cardNumbersIn(Crawler $page, string $name): array
    {
        return $this->column($page, $name)->filter('.col-cards .card .top span:first-child')->each(static fn (Crawler $number): string => $number->text());
    }
}
