<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

#[CoversNothing]
final class DashboardShellTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'the project list' => ['/projects'];
        yield 'the review list' => ['/reviews'];
        yield 'a review' => ['/reviews/round-1'];
        yield 'the components page' => ['/components'];
    }

    #[DataProvider('pages')]
    public function testEveryPageShowsItsContentBesideTheRailAndUnderTheTopBar(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.shell > nav.rail[aria-label="Main"]'));
        self::assertCount(1, $page->filter('.shell > .main > header.topbar'));
        self::assertCount(1, $page->filter('.shell > .main > main.content h1'));
    }

    public function testTheRailHoldsOnlyTheItemsWhosePageIsBuilt(): void
    {
        $rail = $this->browser->request('GET', '/reviews')->filter('.rail a.nav');

        self::assertSame(['Projects'], $rail->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame('/projects', $rail->attr('href'));
        self::assertCount(0, $rail->filter('.count'));
    }

    public function testTheRailMarksTheProjectsItemCurrentOnTheProjectsPage(): void
    {
        $page = $this->browser->request('GET', '/projects');

        self::assertSame('page', $page->filter('.rail a.nav[data-k="projects"]')->attr('aria-current'));
    }

    public function testTheRailMarksNoItemCurrentOnAPageWithoutOne(): void
    {
        $page = $this->browser->request('GET', '/reviews');

        self::assertCount(0, $page->filter('.rail [aria-current]'));
    }

    public function testACollapsedRailStillNamesEachItem(): void
    {
        $item = $this->browser->request('GET', '/reviews')->filter('.rail a.nav[data-k="projects"]');

        self::assertSame('Projects', $item->attr('title'));
    }

    public function testTheLogoLinksToTheDashboardHome(): void
    {
        $brand = $this->browser->request('GET', '/reviews')->filter('.rail a.brand');

        self::assertSame('/', $brand->attr('href'));
        self::assertSame('Intakt home', $brand->attr('aria-label'));
    }

    public function testTheLogosAreServedByTheInstall(): void
    {
        $logos = $this->browser->request('GET', '/reviews')->filter('.rail a.brand img')->each(static fn (Crawler $logo): string => (string) $logo->attr('src'));

        self::assertCount(3, $logos);
        foreach ($logos as $logo) {
            self::assertMatchesRegularExpression('#^/assets/images/#', $logo);
            $this->browser->request('GET', $logo);
            self::assertResponseIsSuccessful();
        }
    }

    public function testTheCollapseButtonControlsTheRail(): void
    {
        $page = $this->browser->request('GET', '/reviews');

        $toggle = $page->filter('.rail button[data-rail-toggle]');
        self::assertSame('rail', $toggle->attr('aria-controls'));
        self::assertSame('Collapse', trim($toggle->text()));
        self::assertCount(1, $page->filter('#rail.rail'));
    }

    public function testTheTopBarHoldsTheSearchFieldWithItsShortcut(): void
    {
        $search = $this->browser->request('GET', '/reviews')->filter('.topbar button.search-trigger');

        self::assertSame('Control+K Meta+K', $search->attr('aria-keyshortcuts'));
        self::assertSame('Ctrl K', $search->filter('kbd')->text());
        self::assertStringContainsString('Search tasks, pull requests, settings', $search->text());
    }

    // Because search arrives with part 3 of #910, a field that promised a dialog would open nothing.
    public function testTheSearchFieldIsMarkedUnavailableUntilSearchIsBuilt(): void
    {
        $search = $this->browser->request('GET', '/reviews')->filter('.topbar button.search-trigger');

        self::assertSame('true', $search->attr('aria-disabled'));
        self::assertNull($search->attr('aria-haspopup'));
    }

    public function testTheTopBarHoldsTheThemeToggle(): void
    {
        $toggle = $this->browser->request('GET', '/reviews')->filter('.topbar button.icon-btn.theme-toggle[data-theme-toggle]');

        self::assertSame('Switch light or dark theme', $toggle->attr('aria-label'));
        self::assertCount(1, $toggle->filter('svg.moon'));
        self::assertCount(1, $toggle->filter('svg.sun'));
    }

    public function testTheShellScriptComesAfterTheShell(): void
    {
        $body = $this->browser->request('GET', '/reviews')->filter('body')->html();

        self::assertGreaterThan(strpos($body, 'data-theme-toggle'), strpos($body, '<script>'));
    }
}
