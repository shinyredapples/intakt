<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewRow;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ListReviewsController::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(ReviewRow::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundStart::class)]
final class ListReviewsControllerTest extends DashboardPageTestCase
{
    public function testTheReviewListShowsEachRoundWithItsProjectNewestFirst(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
        self::assertSame(['round-2', 'round-1'], $crawler->filter('.review-row .round-id code')->each(static fn ($id): string => $id->text()));
        self::assertSame('beta', $crawler->filter('.review-row')->first()->filter('.pname')->text());
        self::assertSame('2 reviewers', $crawler->filter('.review-row')->first()->filter('.reviewers')->text());
    }

    public function testEachRoundIsTitledByItsChangeAndRoundNumberInsteadOfItsId(): void
    {
        $this->ledger->startRoundOf('sample-change', '0195a3c4-7b1e-7c2d-9f00-1a2b3c4d5e6f', 2);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('sample-change, round 2', $crawler->filter('.review-row .review-title')->text());
    }

    public function testTheWholeRowIsOneLinkToTheRoundsReview(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('/reviews/round-1', $crawler->filter('.review-row > a.review-link')->attr('href'));
        self::assertCount(1, $crawler->filter('.review-row > a.review-link .review-title'));
        self::assertCount(1, $crawler->filter('.review-row > a.review-link .review-facts'));
    }

    // Outside the link, because text inside a link cannot be selected without following it.
    public function testTheRoundIdIsASmallDetailOutsideTheLinkThatSelectsWhole(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('round-1', $crawler->filter('.review-row > .round-id code')->text());
        self::assertCount(0, $crawler->filter('.review-link .round-id'));
    }

    public function testTheListOfRoundsIsANamedList(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Review rounds', $crawler->filter('ol.review-rows')->attr('aria-label'));
    }

    public function testAJudgedRoundShowsItsOutcomeChip(): void
    {
        $this->ledger->startRoundOfQuorum('sample-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Approved', $crawler->filter('.review-outcome .chip.approved')->text());
    }

    public function testARoundThatAskedForChangesLeadsItsRowWithTheChangesRequestedChipAndItsIcon(): void
    {
        $this->ledger->startRoundOfQuorum('sample-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $lead = $this->browser->request('GET', '/reviews')->filter('.review-link > *')->first();

        self::assertSame('review-outcome', $lead->attr('class'));
        self::assertSame('Changes requested', $lead->filter('.chip')->text());
        self::assertCount(1, $lead->filter('.chip svg.i'));
    }

    public function testAnOutcomeIsNotRepeatedAmongTheFactsOfItsRow(): void
    {
        $this->ledger->startRoundOfQuorum('sample-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertCount(0, $crawler->filter('.review-facts .chip'));
    }

    public function testARoundWithoutAnOutcomeLeadsWithItsTitleAndKeepsItsStateAmongTheFacts(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('review-title', $crawler->filter('.review-link > *')->first()->attr('class'));
        self::assertCount(0, $crawler->filter('.review-outcome'));
    }

    public function testARoundThatEndedWithoutAnOutcomeInTimeShowsTheBlockedChipAndNoFindingCount(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Blocked', $crawler->filter('.review-outcome .chip')->text());
        self::assertCount(0, $crawler->filter('.review-facts .findings'));
    }

    public function testARoundWhoseJudgingFailedShowsTheFailedChipAndNoFindingCount(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Failed', $crawler->filter('.review-outcome .chip')->text());
        self::assertCount(0, $crawler->filter('.review-facts .findings'));
    }

    public function testAJudgedRoundShowsHowManyFindingsTheJudgeKept(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('2 findings', $crawler->filter('.review-facts .findings')->text());
    }

    public function testAJudgedRoundWithOneFindingSaysFindingInTheSingular(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('1 finding', $crawler->filter('.review-facts .findings')->text());
    }

    public function testARoundInProgressShowsNoFindingCount(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertCount(0, $crawler->filter('.review-facts .findings'));
    }

    public function testARoundShowsWhenItStartedAsARelativeTimeWithTheStampAsItsTooltip(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $time = $this->browser->request('GET', '/reviews')->filter('.review-facts time');

        self::assertSame('just now', $time->text());
        self::assertSame('2026-10-28 12:00:00 UTC', $time->attr('title'));
        self::assertSame('2026-10-28T12:00:00+00:00', $time->attr('datetime'));
    }

    public function testAJudgedRoundWithoutAnOutcomeIsListedAsJudged(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('Judged', $crawler->filter('.review-facts .chip.state-judged')->text());
    }

    public function testARoundWithoutAJudgeDecisionIsListedAsInProgress(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('In progress', $crawler->filter('.review-facts .chip.state-running')->text());
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
        self::assertCount(1, $crawler->filter('.review-row[data-project="beta"]'));
    }

    public function testAProjectParameterTheInstallDoesNotListLeavesTheFilterOnAllProjects(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews?project=gone');

        self::assertCount(0, $crawler->filter('#projfilter option[selected]'), 'Without a selected option the browser shows the first one, All projects.');
        self::assertSame(['round-1'], $crawler->filter('.review-row .round-id code')->each(static fn ($id): string => $id->text()));
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
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "Invalid query parameter "project"."', NotFoundHttpException::class));
    }

    public function testTheReviewListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/reviews');

        self::assertSelectorTextContains('main .empty', 'No review has started yet.');
        self::assertSelectorNotExists('.review-rows');
        self::assertSelectorNotExists('.failing-heads');
    }

    public function testTheEmptyReviewListShowsTheWaitingOctopusAt144PxAboveItsUnchangedWords(): void
    {
        $empty = $this->browser->request('GET', '/reviews')->filter('main .empty');
        $images = $empty->filter('img.character');

        self::assertSame(['No reviews yet', 'No review has started yet.'], [$empty->filter('h2')->text(), $empty->filter('p')->text()]);
        self::assertSame([['', '144', '144'], ['', '144', '144']], $images->each(static fn (Crawler $image): array => [$image->attr('alt'), $image->attr('width'), $image->attr('height')]));
        self::assertSame(1, preg_match('~octopus-waiting-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-waiting-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
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

    // Needed because the board's banners link to this anchor.
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
