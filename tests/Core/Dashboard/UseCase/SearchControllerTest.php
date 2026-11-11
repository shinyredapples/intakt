<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ResultGroup;
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
        self::assertSame('alpha', $row->filter('.t')->text());
        self::assertSame('Project, 2 review rounds', $row->filter('.d')->text());
        self::assertSame('/reviews?project=alpha', $row->filter('.t a')->attr('href'));
    }

    public function testAProjectWithOneRoundSaysRoundInTheSingular(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/search?q=alpha');

        self::assertSame('Project, 1 review round', $this->rowsOf($crawler, 'Projects')->filter('.d')->text());
    }

    public function testAReviewResultNamesItsProjectAndStateAndLinksToItsPage(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/search?q=round-1');

        $row = $this->rowsOf($crawler, 'Reviews');
        self::assertSame('Round round-1', $row->filter('.t')->text());
        self::assertSame('alpha, judged', $row->filter('.d')->text());
        self::assertSame('/reviews/round-1', $row->filter('.t a')->attr('href'));
    }

    public function testEveryWordOfTheQueryMustMatch(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/search?q=beta+progress');

        self::assertSame(['Reviews'], $this->groupsOf($crawler));
        self::assertSame(['Round round-2'], $this->rowsOf($crawler, 'Reviews')->filter('.t')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testAPageIsFoundByItsName(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=components');

        $row = $this->rowsOf($crawler, 'Pages');
        self::assertSame('Components', $row->filter('.t')->text());
        self::assertSame('/components', $row->filter('.t a')->attr('href'));
        self::assertStringStartsWith('All projects', $row->filter('.d')->text());
    }

    public function testAnActionIsFoundByItsWordsAndHasNoLink(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=theme');

        $row = $this->rowsOf($crawler, 'Actions');
        self::assertSame('Switch light or dark theme', $row->filter('.t')->text());
        self::assertSame('All projects', $row->filter('.d')->text());
        self::assertCount(0, $row->filter('a'));
    }

    public function testAnEmptyQueryShowsTheNewestRoundsAsRecentThenTheActions(): void
    {
        foreach (range(1, 6) as $number) {
            $this->ledger->startRound('round-'.$number, 'alpha');
        }

        $crawler = $this->browser->request('GET', '/search?q=+');

        self::assertSame(['Recent', 'Actions'], $this->groupsOf($crawler));
        self::assertSame(
            ['Round round-6', 'Round round-5', 'Round round-4', 'Round round-3', 'Round round-2'],
            $this->rowsOf($crawler, 'Recent')->filter('.t')->each(static fn (Crawler $title): string => $title->text()),
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
        self::assertSame('No results for “zebra”', $crawler->filter('.empty h2')->text());
        self::assertSame('Try a project such as alpha, a round id, or a page such as Reviews.', $crawler->filter('.empty p')->text());
        $form = $crawler->filter('form');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/search', $form->attr('action'));
        self::assertSame('Clear the search', $form->filter('button.btn.secondary[type="submit"]')->text());
    }

    public function testWithoutProjectsTheSuggestionNamesOnlyPages(): void
    {
        $crawler = $this->browser->request('GET', '/search?q=zebra');

        self::assertSame('Try a page such as Reviews or Projects.', $crawler->filter('.empty p')->text());
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

        $section = $crawler->filter('section')->first();
        self::assertCount(1, $section->filter('h2 svg.i'));
        self::assertSame($section->filter('h2')->attr('id'), $section->attr('aria-labelledby'));
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
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "Invalid query parameter "q"."', NotFoundHttpException::class));
    }

    /**
     * @return list<string>
     */
    private function groupsOf(Crawler $crawler): array
    {
        return $crawler->filter('section > h2')->each(static fn (Crawler $heading): string => $heading->text());
    }

    private function rowsOf(Crawler $crawler, string $group): Crawler
    {
        return $crawler->filter('section')->reduce(static fn (Crawler $section): bool => $group === $section->filter('h2')->text())->filter('.list .row');
    }
}
