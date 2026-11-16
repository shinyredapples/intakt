<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;

#[CoversNothing]
final class ProjectBreadcrumbTest extends DashboardPageTestCase
{
    public function testAReviewPageLeadsBackThroughProjectsAndItsProjectToItsChange(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $crumbs = $this->crumbsOf('/reviews/round-1');

        self::assertSame(['Projects', 'alpha', 'sample-change'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame(['/projects', '/reviews?project=alpha'], $crumbs->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testOnlyTheLastCrumbIsTheCurrentPage(): void
    {
        $this->ledger->startRoundOf('sample-change', 'round-1', 1);

        $crumbs = $this->crumbsOf('/reviews/round-1');

        self::assertSame([null, null, 'page'], $crumbs->each(static fn (Crawler $crumb): ?string => $crumb->filter('[aria-current]')->count() > 0 ? $crumb->filter('[aria-current]')->attr('aria-current') : null));
        self::assertCount(0, $crumbs->last()->filter('a'));
    }

    public function testTheBreadcrumbOfAReviewWithoutAChangeEndsWithItsRound(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertSame('round round-1', trim($this->crumbsOf('/reviews/round-1')->last()->text()));
    }

    public function testTheBreadcrumbEscapesTheProjectName(): void
    {
        $this->ledger->startRound('round-1', '<b>alpha</b>');

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('<b>alpha</b>', trim($page->filter('nav.crumbs li')->eq(1)->text()));
        self::assertCount(0, $page->filter('nav.crumbs b'));
    }

    public function testAProjectsReviewListLeadsBackToProjects(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crumbs = $this->crumbsOf('/reviews?project=alpha');

        self::assertSame(['Projects', 'alpha'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame(['/projects'], $crumbs->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame('page', $crumbs->last()->filter('[aria-current]')->attr('aria-current'));
    }

    public function testTheReviewListOfAProjectNamedZeroLeadsBackToProjects(): void
    {
        $this->ledger->startRound('round-1', '0');

        self::assertSame(['Projects', '0'], $this->crumbsOf('/reviews?project=0')->each(static fn (Crawler $crumb): string => trim($crumb->text())));
    }

    public function testAReviewListOfAProjectTheInstallDoesNotListShowsNoBreadcrumb(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews?project=missing');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('nav.crumbs'));
    }

    public function testAProjectsReviewListNamesTheProjectOfItsBreadcrumbForTheProjectFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertSame('alpha', $this->browser->request('GET', '/reviews?project=alpha')->filter('nav.crumbs')->attr('data-project-trail'));
    }

    public function testTheBreadcrumbOfAReviewPageStaysWhateverTheProjectFilterShows(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertNull($this->browser->request('GET', '/reviews/round-1')->filter('nav.crumbs')->attr('data-project-trail'));
    }

    public function testTheUnfilteredReviewListShowsNoBreadcrumb(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertCount(0, $this->browser->request('GET', '/reviews')->filter('nav.crumbs'));
    }

    public function testAReviewListWithAnEmptyProjectShowsNoBreadcrumb(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertCount(0, $this->browser->request('GET', '/reviews?project=')->filter('nav.crumbs'));
    }

    public function testTheBreadcrumbSitsAboveThePageTitle(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $page->filter('main.content nav.crumbs[aria-label="Breadcrumb"] + .page-head h1'));
    }

    private function crumbsOf(string $path): Crawler
    {
        $page = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $page->filter('nav.crumbs[aria-label="Breadcrumb"] > ol > li');
    }
}
