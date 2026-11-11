<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

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
    public function testEveryPageShowsItsContentBesideTheRailAndUnderTheTopBarWithTheHiddenReconnectingLineAndOfflineMarker(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.shell > nav.rail[aria-label="Main"]'));
        self::assertCount(1, $page->filter('.shell > .main > header.topbar'));
        self::assertCount(1, $page->filter('.shell > .main > main.content h1'));

        $line = $page->filter('.shell[data-controller="connection"] main.content > .conn:first-child');
        self::assertSame('line', $line->attr('data-connection-target'));
        self::assertSame('', $line->attr('hidden'));

        $marker = $page->filter('.shell[data-controller="connection"] header.topbar > .offline-marker:last-child');
        self::assertSame('marker', $marker->attr('data-connection-target'));
        self::assertSame('', $marker->attr('hidden'));
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

    public function testEveryLogoOfTheShellIsServedByTheInstall(): void
    {
        $page = $this->browser->request('GET', '/reviews');
        $rail = $page->filter('.rail a.brand img')->each(static fn (Crawler $logo): string => (string) $logo->attr('src'));
        $phone = $page->filter('.topbar a.brand-m img')->each(static fn (Crawler $logo): string => (string) $logo->attr('src'));

        self::assertCount(3, $rail);
        self::assertCount(2, $phone);
        foreach ($phone as $logo) {
            self::assertMatchesRegularExpression('#^/assets/images/intakt-lockup#', $logo);
        }
        foreach ([...$rail, ...$phone] as $logo) {
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

    public function testTheTopBarHoldsTheProjectFilterBetweenTheSearchFieldAndTheThemeToggle(): void
    {
        $controls = $this->browser->request('GET', '/reviews')->filter('.topbar > *')->each(static fn (Crawler $control): string => (string) $control->attr('class'));

        self::assertSame(['brand-m', 'search-trigger', 'grow', 'select', 'icon-btn theme-toggle', 'offline-marker'], $controls);
    }

    public function testTheProjectFilterIsASelectNamedProject(): void
    {
        $filter = $this->browser->request('GET', '/reviews')->filter('.topbar select#projfilter');

        self::assertCount(1, $filter);
        self::assertSame('Project', $filter->attr('aria-label'));
    }

    public function testTheProjectFilterOffersAllProjectsAndEveryProjectOnceInNameOrder(): void
    {
        $this->ledger->startRound('round-1', 'beta');
        $this->ledger->startRound('round-2', 'alpha');
        $this->ledger->startRound('round-3', 'beta');
        $this->ledger->startRound('round-4', 'Gamma');

        $options = $this->browser->request('GET', '/reviews')->filter('#projfilter option');

        self::assertSame(['All projects', 'alpha', 'beta', 'Gamma'], $options->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame(['', 'alpha', 'beta', 'Gamma'], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function testTheProjectFilterStartsAtAllProjects(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $chosen = $this->browser->request('GET', '/reviews')->filter('#projfilter option[selected]');

        self::assertSame(['All projects'], $chosen->each(static fn (Crawler $option): string => $option->text()));
    }

    // Because a project may be named "all", the choice of every project needs a value no project name can take.
    public function testAProjectNamedAllStaysApartFromAllProjects(): void
    {
        $this->ledger->startRound('round-1', 'all');

        $values = $this->browser->request('GET', '/reviews')->filter('#projfilter option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));

        self::assertSame(['', 'all'], $values);
    }

    public function testTheProjectFilterOfAnInstallWithoutReviewsOffersOnlyAllProjects(): void
    {
        $options = $this->browser->request('GET', '/projects')->filter('#projfilter option');

        self::assertSame(['All projects'], $options->each(static fn (Crawler $option): string => $option->text()));
    }

    public function testEveryReviewRowNamesItsProjectForTheFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $rows = $this->browser->request('GET', '/reviews')->filter('main.content tbody tr');

        self::assertSame(['beta', 'alpha'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-project')));
    }

    public function testEveryProjectRowNamesItsProjectForTheFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $rows = $this->browser->request('GET', '/projects')->filter('main.content .list > [data-project]');

        self::assertEqualsCanonicalizing(['alpha', 'beta'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-project')));
    }

    public function testEveryFailingHeadNamesItsProjectForTheFilter(): void
    {
        $this->ledger->failHead('alpha', '7', 2);

        $heads = $this->browser->request('GET', '/reviews')->filter('.failing-heads article');

        self::assertSame(['alpha'], $heads->each(static fn (Crawler $head): string => (string) $head->attr('data-project')));
    }

    public function testTheShellScriptComesAfterTheShell(): void
    {
        $body = $this->browser->request('GET', '/reviews')->filter('body')->html();

        self::assertGreaterThan(strpos($body, 'data-theme-toggle'), strpos($body, '<script>'));
    }

    public function testTheRenderedLayoutWiresTheListenerForTheProjectFilterStream(): void
    {
        $page = $this->browser->request('GET', '/reviews');

        $script = $page->filter('body > script')->text();
        self::assertStringContainsString("addEventListener('turbo:before-stream-render'", $script);
        self::assertStringContainsString("'projfilter'", $script);
        self::assertCount(1, $page->filter('select#projfilter'));
    }

    public function testThePushedProjectFilterIsRenderedWithTheOptionMarkupOfTheLayout(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $stream = new Crawler($twig->render('dashboard/stream/project_filter.stream.html.twig', ['projects' => ['alpha']]));
        $page = $this->browser->request('GET', '/reviews');

        $pushed = $stream->filter('template')->html();
        self::assertSame(str_replace(' selected', '', trim($page->filter('#projfilter')->html())), $pushed);
    }
}
