<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ResultGroup;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\SearchAction;
use Intakt\Core\Dashboard\Domain\SearchAnswer;
use Intakt\Core\Dashboard\Domain\SearchGroup;
use Intakt\Core\Dashboard\Domain\SearchQuery;
use Intakt\Core\Dashboard\Domain\SearchResult;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\SearchIndex;
use Intakt\Core\Dashboard\UseCase\Search\SearchController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(SearchController::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(SearchAnswer::class)]
#[CoversClass(ResultGroup::class)]
#[CoversClass(SearchResult::class)]
#[CoversClass(SearchGroup::class)]
#[CoversClass(SearchAction::class)]
#[UsesClass(SearchQuery::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundStart::class)]
final class SearchControllerTest extends DashboardPageTestCase
{
    public function testResultsComeInGroupsInTheOrderActionsReviewsProjectsPages(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=a');

        self::assertResponseIsSuccessful();
        self::assertSame(['Actions', 'Reviews', 'Projects', 'Pages'], $this->groupsOf($crawler));
    }

    public function testAProjectResultNamesItsRoundsAndLinksToItsReviews(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=alpha');

        $row = $this->rowsOf($crawler, 'Projects')->first();
        self::assertSame('alpha', $row->filter('.lbl')->text());
        self::assertSame('Project, 2 review rounds', $row->filter('.sub')->text());
        self::assertSame('/reviews?project=alpha', $row->attr('href'));
    }

    public function testAProjectWithOneRoundSaysRoundInTheSingular(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=alpha');

        self::assertSame('Project, 1 review round', $this->rowsOf($crawler, 'Projects')->filter('.sub')->text());
    }

    public function testAReviewResultIsTitledByItsChangeAndRoundAndNamesItsProjectAndState(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 2);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/search?q=round-1');

        $row = $this->rowsOf($crawler, 'Reviews');
        self::assertSame('sample-change, round 2', $row->filter('.lbl')->text());
        self::assertSame('alpha, judged', $row->filter('.sub')->text());
    }

    public function testAJudgedReviewResultLeadsWithItsChangesRequestedChipBeforeItsTitle(): void
    {
        $this->ledger->startRoundOfQuorum('sample-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 2);
        $this->ledger->judgeRound('round-1');

        $row = $this->rowsOf($this->browser->request('GET', '/search?q=round-1'), 'Reviews');

        self::assertSame('Changes requested', $row->filter('.res-outcome .chip')->text());
        self::assertSame('res-outcome', $row->children()->first()->attr('class'));
        self::assertCount(1, $row->filter('.res-outcome .chip svg'));
        self::assertSame('alpha, judged', $row->filter('.sub')->text());
    }

    public function testOnlyAnApprovedReviewResultShowsTheApprovedChip(): void
    {
        $this->ledger->startRoundOfQuorum('good-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOfQuorum('bad-change', 'round-2', 'all');
        $this->ledger->answer('round-2', 'correctness', 0);
        $this->ledger->answer('round-2', 'security', 1);
        $this->ledger->judgeRound('round-2');

        $crawler = $this->browser->request('GET', '/search?q=change');

        self::assertCount(1, $this->rowsOf($crawler, 'Reviews')->filter('.chip.approved'));
        self::assertCount(2, $this->rowsOf($crawler, 'Reviews')->filter('.chip'));
    }

    public function testAReviewStillInReviewShowsNoOutcomeChipAndKeepsItsStateAsSecondaryText(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $row = $this->rowsOf($this->browser->request('GET', '/search?q=sample'), 'Reviews');

        self::assertCount(0, $row->filter('.chip'));
        self::assertSame('alpha, in progress', $row->filter('.sub')->text());
    }

    public function testNoResultShowsTheBareWordsChangesVerdictOrActAsItsOutcome(): void
    {
        $this->ledger->startRoundOfQuorum('sample-change', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 2);
        $this->ledger->judgeRound('round-1');

        $row = $this->rowsOf($this->browser->request('GET', '/search?q=round-1'), 'Reviews');

        self::assertDoesNotMatchRegularExpression('/\b(changes|verdict|act)\b(?! requested)/i', $row->text());
    }

    public function testTheWholeReviewRowIsTheLinkToItsPage(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 2);

        $row = $this->rowsOf($this->browser->request('GET', '/search?q=sample'), 'Reviews');

        self::assertSame('a', $row->nodeName());
        self::assertSame('/reviews/round-1', $row->attr('href'));
        self::assertCount(0, $row->filter('.res a, .res button'));
    }

    public function testAReviewRowShowsItsRoundIdAsACopyableDetailAndNotInItsTitle(): void
    {
        $this->ledger->startRoundOf('sample-change', '0b7e2c1a-5d3f-4e8a-9c6b-2f1d0a9e8b7c', 2);

        $row = $this->rowsOf($this->browser->request('GET', '/search?q=sample'), 'Reviews');

        self::assertSame('0b7e2c1a-5d3f-4e8a-9c6b-2f1d0a9e8b7c', $row->filter('.round-id code')->text());
        self::assertStringNotContainsString('0b7e2c1a', $row->filter('.lbl')->text());
    }

    public function testAResultThatIsNoReviewShowsNoRoundId(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=alpha');

        self::assertCount(0, $this->rowsOf($crawler, 'Projects')->filter('.round-id'));
    }

    public function testEveryWordOfTheQueryMustMatch(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/search?q=beta+progress');

        self::assertSame(['Reviews'], $this->groupsOf($crawler));
        self::assertSame(['round-2'], $this->rowsOf($crawler, 'Reviews')->filter('.round-id code')->each(static fn (Crawler $id): string => $id->text()));
    }

    public function testAPageIsFoundByItsName(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=components');

        $row = $this->rowsOf($crawler, 'Pages');
        self::assertSame('Components', $row->filter('.lbl')->text());
        self::assertSame('/components', $row->attr('href'));
        self::assertStringStartsWith('All projects', $row->filter('.sub')->text());
    }

    public function testAnActionIsFoundByItsWordsAndHasNoLink(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=theme');

        $row = $this->rowsOf($crawler, 'Actions');
        self::assertSame('Switch light or dark theme', $row->filter('.lbl')->text());
        self::assertSame('All projects', $row->filter('.sub')->text());
        self::assertSame('div', $row->nodeName());
        self::assertNull($row->attr('href'));
    }

    public function testAddAProjectIsAnActionThatLinksToTheFlow(): void
    {
        $row = $this->rowsOf($this->browser->request('GET', '/search?q=add'), 'Actions');

        self::assertSame('Add a project', $row->filter('.lbl')->text());
        self::assertSame('a', $row->nodeName());
        self::assertSame('/projects/new', $row->attr('href'));
        self::assertNull($row->attr('data-search-action'));
    }

    public function testAnActionRowNamesTheActionTheOverlayRuns(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=theme');

        self::assertSame(SearchAction::SwitchTheme->value, $this->rowsOf($crawler, 'Actions')->attr('data-search-action'));
    }

    public function testEveryActionHasAHandlerInTheOverlayScript(): void
    {
        $script = file_get_contents(__DIR__.'/../../../../assets/search.js');
        self::assertIsString($script);
        preg_match('/const ACTIONS = \{(.*?)\n\};/s', $script, $handlers);
        preg_match_all("/^    '([\\w-]+)': /m", $handlers[1] ?? '', $names);

        self::assertSame(array_column(SearchAction::cases(), 'value'), $names[1]);
    }

    public function testALinkedResultCarriesNoAction(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=components');

        self::assertNull($this->rowsOf($crawler, 'Pages')->attr('data-search-action'));
    }

    public function testEveryResultIsAnUnselectedOptionThatIsNoTabStop(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $rows = $this->browser->request('GET', '/search?q=a')->filter('.res');

        self::assertGreaterThan(1, $rows->count());
        $rows->each(static function (Crawler $row): void {
            self::assertSame('option', $row->attr('role'));
            self::assertSame('-1', $row->attr('tabindex'));
            self::assertSame('false', $row->attr('aria-selected'));
        });
    }

    public function testAnEmptyQueryShowsTheNewestRoundsAsRecentThenTheActions(): void
    {
        foreach (range(1, 6) as $number) {
            $this->ledger->startRoundOf('sample-change', 'round-'.$number, $number);
        }

        $crawler = $this->browser->request('GET', '/search?q=+');

        self::assertSame(['Recent', 'Actions'], $this->groupsOf($crawler));
        self::assertSame(
            ['sample-change, round 6', 'sample-change, round 5', 'sample-change, round 4', 'sample-change, round 3', 'sample-change, round 2'],
            $this->rowsOf($crawler, 'Recent')->filter('.lbl')->each(static fn (Crawler $title): string => $title->text()),
        );
    }

    public function testNoQueryParameterIsAnEmptyQuery(): void
    {
        $crawler = $this->browser->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertSame(['Actions'], $this->groupsOf($crawler));
    }

    public function testAQueryWithoutResultsSuggestsAProjectAndOffersToClearTheSearch(): void
    {
        $this->ledger->startRound('round-1', 'beta');
        $this->ledger->startRound('round-2', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=zebra');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->groupsOf($crawler));
        self::assertSame('No results for “zebra”', $crawler->filter('.none p strong')->text());
        self::assertSame('Try a project such as alpha, a round id, or a page such as Reviews.', $crawler->filter('.none p + p')->text());
        $form = $crawler->filter('.none form');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/search', $form->attr('action'));
        $clear = $form->filter('button.btn.secondary.sm[type="submit"]');
        self::assertSame('Clear the search', $clear->text());
        self::assertSame(SearchAction::ClearSearch->value, $clear->attr('data-search-action'));
    }

    public function testWithoutProjectsTheSuggestionNamesOnlyPages(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=zebra');

        self::assertSame('Try a page such as Reviews or Projects.', $crawler->filter('.none p + p')->text());
    }

    public function testEachGroupShowsAtMostTenResults(): void
    {
        foreach (range(1, 12) as $number) {
            $this->ledger->startRound('round-'.$number, 'alpha');
        }

        $crawler = $this->browser->request('GET', '/search?q=round');

        self::assertCount(10, $this->rowsOf($crawler, 'Reviews'));
    }

    public function testTheResultsAreAFragmentWithoutTheShell(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=a');

        self::assertCount(0, $crawler->filter('html .shell, .topbar, script'));
        self::assertStringNotContainsString('<html', (string) $this->browser->getResponse()->getContent());
    }

    public function testEveryGroupHeadingCarriesItsIconAndNamesItsSection(): void
    {
        $crawler = $this->browser->request('GET', '/search');

        $group = $crawler->filter('[role="group"]')->first();
        self::assertCount(1, $group->filter('.grp svg.i'));
        self::assertSame($group->filter('.grp')->attr('id'), $group->attr('aria-labelledby'));
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $this->ledger->startRound('round-1', '<b>bold</b>');

        $this->browser->request('GET', '/search?q=bold');

        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('<b>', (string) $this->browser->getResponse()->getContent());
    }

    public function testAQueryWithMarkupIsEscapedInTheNoResultsLine(): void
    {
        $this->browser->request('GET', '/search?q=%3Cb%3Ezebra');

        self::assertStringContainsString('No results for “&lt;b&gt;zebra”', (string) $this->browser->getResponse()->getContent());
    }

    public function testAQueryThatIsNotValidUtf8FindsNothing(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=%FF');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->groupsOf($crawler));
    }

    public function testAListOfQueriesIsRefused(): void
    {
        $this->browser->request('GET', '/search?q[]=alpha');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "Invalid query parameter "q"."', NotFoundHttpException::class));
    }

    /**
     * @return list<string>
     */
    private function groupsOf(Crawler $crawler): array
    {
        return $crawler->filter('[role="group"] > .grp')->each(static fn (Crawler $heading): string => $heading->text());
    }

    private function rowsOf(Crawler $crawler, string $group): Crawler
    {
        return $crawler->filter('[role="group"]')->reduce(static fn (Crawler $section): bool => $group === $section->filter('.grp')->text())->filter('.res');
    }
}
