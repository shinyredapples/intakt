<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
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
        yield 'the board' => ['/'];
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

    public function testTheRailHoldsNowYouAndHistoryWithProjectsUnderMore(): void
    {
        $rail = $this->browser->request('GET', '/reviews')->filter('.rail a.nav');

        self::assertSame(['Now', 'You', 'History', 'Projects'], $rail->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['/?board=1', '/waiting', '/history', '/projects'], $rail->each(static fn (Crawler $item): string => (string) $item->attr('href')));
        self::assertSame(['nav', 'nav', 'nav', 'nav in-more'], $rail->each(static fn (Crawler $item): string => (string) $item->attr('class')));
        self::assertCount(0, $rail->filter('.count'));
    }

    public function testTheYouItemCountsWhatItsPageLists(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $notices->method('stillRunningOldestFirst')->willReturn([$notice, $notice]);
        self::getContainer()->set(OwnerNotices::class, $notices);

        $count = $this->browser->request('GET', '/trends')->filter('.rail a.nav[data-k="waiting"] .count');

        self::assertSame(['2'], $count->each(static fn (Crawler $badge): string => $badge->text()));
        self::assertSame('true', $count->attr('aria-hidden'));
    }

    public function testTheYouLinkKeepsItsNameAlongWithTheWaitingCountWhenTheRailIsCollapsed(): void
    {
        $notices = self::createStub(OwnerNotices::class);
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $notices->method('stillRunningOldestFirst')->willReturn([$notice, $notice, $notice]);
        self::getContainer()->set(OwnerNotices::class, $notices);

        $you = $this->browser->request('GET', '/trends')->filter('.rail a.nav[data-k="waiting"]');

        self::assertSame('You, 3 waiting', $you->attr('aria-label'));
    }

    public function testEveryCurrentMarkOfTheRailOnAProjectsReviewListCarriesTheMarkTheShellClears(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertGreaterThan(0, $page->filter('.rail [aria-current]')->count());
        self::assertCount(0, $page->filter('.rail [aria-current]:not([data-section-current])'));
    }

    public function testTheRailMarksTheBoardItemCurrentOnTheBoard(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(['board'], $page->filter('.rail [aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
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

    public function testTheRailMarksOnlyProjectsAndItsMoreItemCurrentOnAReviewPage(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['projects', 'more'], $page->filter('.rail .nav[aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
    }

    public function testTheRailMarksOnlyProjectsAndItsMoreItemCurrentOnAProjectsReviewList(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertSame(['projects', 'more'], $page->filter('.rail .nav[aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
    }

    public function testTheRailMarksNoItemCurrentOnAReviewListOfAProjectTheInstallDoesNotList(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        self::assertCount(0, $this->browser->request('GET', '/reviews?project=missing')->filter('.rail [aria-current]'));
    }

    public function testTheRailTellsTheShellWhichItemItMarksCurrentForItsSectionAlone(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', '/reviews?project=alpha');

        self::assertSame(['projects', 'more', ''], $page->filter('.rail [data-section-current]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
        self::assertCount(0, $this->browser->request('GET', '/projects')->filter('.rail [data-section-current]'));
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

    public function testTheSearchFieldOpensTheSearchDialog(): void
    {
        $search = $this->browser->request('GET', '/reviews')->filter('.topbar button.search-trigger');

        self::assertNull($search->attr('aria-disabled'));
        self::assertSame('dialog', $search->attr('aria-haspopup'));
        self::assertSame('search', $search->attr('aria-controls'));
    }

    #[DataProvider('pages')]
    public function testEveryPageHoldsTheClosedSearchDialogOutsideTheShell(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);

        $dialog = $page->filter('body > .shell + .overlay#search[role="dialog"][aria-modal="true"][aria-label="Search"][hidden]');
        self::assertCount(1, $dialog);
        self::assertSame('/search', $dialog->attr('data-search-url'));
        self::assertCount(0, $page->filter('.shell .overlay'));
    }

    public function testTheSearchFieldOfTheDialogControlsItsListOfResults(): void
    {
        $dialog = $this->browser->request('GET', '/reviews')->filter('#search .palette');

        $field = $dialog->filter('.q input#search-q');
        self::assertSame('combobox', $field->attr('role'));
        self::assertSame('Search', $field->attr('aria-label'));
        self::assertSame('off', $field->attr('autocomplete'));
        self::assertSame('search-results', $field->attr('aria-controls'));
        self::assertCount(1, $dialog->filter('.results#search-results[role="listbox"][aria-label="Results"]:empty'));
        self::assertSame('Esc', $dialog->filter('.q kbd')->text());
    }

    public function testTheSearchDialogCarriesACancelButton(): void
    {
        $cancel = $this->browser->request('GET', '/reviews')->filter('#search .q button[data-search-cancel][type="button"]');

        self::assertCount(1, $cancel);
        self::assertSame('Cancel', $cancel->text());
    }

    public function testTheSearchDialogNamesItsKeys(): void
    {
        $footer = $this->browser->request('GET', '/reviews')->filter('#search .palette footer');

        self::assertSame(['↑ ↓ move', 'Enter open', 'Esc close'], $footer->filter('span')->each(static fn (Crawler $hint): string => $hint->text()));
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

        $rows = $this->browser->request('GET', '/reviews')->filter('main.content .review-row');

        self::assertSame(['beta', 'alpha'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-project')));
    }

    public function testEveryProjectRowNamesItsProjectForTheFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $rows = $this->browser->request('GET', '/projects')->filter('main.content #project-cards > [data-project]');

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
