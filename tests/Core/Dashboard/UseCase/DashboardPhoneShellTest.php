<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversNothing]
final class DashboardPhoneShellTest extends DashboardPageTestCase
{
    public function testThePhoneTopBarStartsWithTheLogoLinkedToTheDashboardHome(): void
    {
        $brand = $this->browser->request('GET', '/reviews')->filter('.topbar > a.brand-m:first-child');

        self::assertSame('/', $brand->attr('href'));
        self::assertSame('Intakt home', $brand->attr('aria-label'));
        self::assertSame(['logo-light', 'logo-dark'], $brand->filter('img')->each(static fn (Crawler $logo): string => (string) $logo->attr('class')));
    }

    public function testThePhoneSearchButtonCarriesItsShortLabel(): void
    {
        $search = $this->browser->request('GET', '/reviews')->filter('.topbar button.search-trigger');

        self::assertSame('Search', $search->filter('.ph-short')->text());
    }

    // A phone's bar holds Now, You and Trends, so Projects is reached through More.
    public function testTheMoreItemOpensTheSheetWithProjects(): void
    {
        $page = $this->browser->request('GET', '/projects');

        self::assertCount(1, $page->filter('.rail-in button.nav.more[data-more-toggle]'));
        self::assertSame(['Projects'], $page->filter('#more.menu[data-more-sheet] nav a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function testTheMoreItemOpensTheSheetOfThePagesUnderIt(): void
    {
        $rail = $this->railWithHistory(DashboardRoutes::LIST_PROJECTS);

        $more = $rail->filter('.rail-in button.nav.more[data-more-toggle]');
        self::assertSame('More', trim($more->text()));
        self::assertSame('More', $more->attr('title'));
        self::assertSame('more', $more->attr('aria-controls'));
        self::assertSame('false', $more->attr('aria-expanded'));
        $sheet = $rail->filter('#more.menu[data-more-sheet]');
        self::assertNotNull($sheet->attr('hidden'));
        self::assertSame(['History'], $sheet->filter('nav a')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame('/reviews', $sheet->filter('nav a')->attr('href'));
    }

    public function testThePagesUnderMoreAreRailItemsOnADesktop(): void
    {
        $items = $this->railWithHistory(DashboardRoutes::LIST_PROJECTS)->filter('.rail-in a.nav');

        self::assertSame(['Projects', 'History'], $items->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['nav', 'nav in-more'], $items->each(static fn (Crawler $item): string => (string) $item->attr('class')));
    }

    public function testTheMoreItemIsCurrentOnAPageUnderIt(): void
    {
        $rail = $this->railWithHistory(DashboardRoutes::LIST_REVIEWS);

        self::assertSame('page', $rail->filter('[data-more-toggle]')->attr('aria-current'));
        self::assertSame('page', $rail->filter('[data-more-sheet] a')->attr('aria-current'));
    }

    public function testTheMoreItemIsNotCurrentOnAnotherPage(): void
    {
        $rail = $this->railWithHistory(DashboardRoutes::LIST_PROJECTS);

        self::assertNull($rail->filter('[data-more-toggle]')->attr('aria-current'));
        self::assertNull($rail->filter('[data-more-sheet] a')->attr('aria-current'));
        self::assertSame('page', $rail->filter('a.nav[data-k="projects"]')->attr('aria-current'));
    }

    public function testOnPhonesTheRailIsA68PxBarAtTheBottomOfTheScreen(): void
    {
        [$components, $tokens] = $this->sheets('components', 'tokens');
        $rule = $this->phoneRulesOf($components)['.rail-in'] ?? '';

        self::assertMatchesRegularExpression('/position:\s*fixed;\s*top:\s*auto;\s*bottom:\s*0;\s*left:\s*0;\s*right:\s*0/', $rule);
        self::assertStringContainsString('height: calc(var(--bar) + env(safe-area-inset-bottom))', $rule);
        self::assertStringContainsString('flex-direction: row', $rule);
        self::assertStringContainsString('--bar: 68px;', $tokens);
    }

    public function testOnPhonesTheRailTakesNoRowOfTheShellAboveTheTopBar(): void
    {
        $rule = $this->phoneRules()['.rail'] ?? '';

        self::assertMatchesRegularExpression('/position:\s*absolute/', $rule);
        self::assertMatchesRegularExpression('/height:\s*0/', $rule);
    }

    public function testOnPhonesTheBarShowsTheMoreItemInsteadOfThePagesUnderIt(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('display: none', $rules['.rail .brand, .rail .spacer, .rail .collapse, .rail .nav.in-more'] ?? '');
        self::assertStringContainsString('display: flex', $rules['.rail .nav.more'] ?? '');
    }

    public function testOnADesktopThePhonePiecesAreHidden(): void
    {
        $rules = $this->rulesOf($this->sheets('components')[0]);

        self::assertStringContainsString('display: none', $rules['.topbar .brand-m, .search-trigger .ph-short, .rail .nav.more, .menu'] ?? '');
    }

    public function testOnPhonesTheSearchFillsTheWholeScreenWithoutRadiusOrScrimGap(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('padding: 0', $rules['.overlay:not([hidden])'] ?? '');
        self::assertStringContainsString('height: 100dvh', $rules['.overlay .palette'] ?? '');
        self::assertStringContainsString('border-radius: 0', $rules['.overlay .palette'] ?? '');
        self::assertStringContainsString('max-width: none', $rules['.overlay .palette'] ?? '');
        self::assertStringContainsString('max-height: none', $rules['.overlay .palette .results'] ?? '');
    }

    public function testOnPhonesASearchResultRowIsAtLeast52PixelsTall(): void
    {
        self::assertStringContainsString('min-height: 52px', $this->phoneRules()['.palette .res'] ?? '');
    }

    public function testOnPhonesSubTabsAndTheLinksOfRowsAndTableCellsAreAtLeast44PixelsTall(): void
    {
        self::assertStringContainsString('min-height: 44px', $this->phoneRules()['.subtabs a, .row .t a, .ledger td a'] ?? '');
    }

    public function testOnPhonesTheSectionLinksOfTheComponentsPageAreAtLeast44PixelsTall(): void
    {
        self::assertStringContainsString('min-height: 44px', $this->phoneRules()['.comp-nav a'] ?? '');
    }

    public function testOnPhonesTheSmallestTextIs15PixelsAlsoInsideAPaneWithItsOwnTheme(): void
    {
        self::assertStringContainsString('--t13: 15px', $this->phoneRulesOf($this->sheets('tokens')[0])[':root, [data-theme]'] ?? '');
    }

    public function testOnPhonesTheCancelAndClearButtonsOfTheSearchAreAtLeast44PixelsTall(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('min-height: 44px', $rules['.overlay .palette .q .btn'] ?? '');
        self::assertStringContainsString('min-height: 44px', $rules['.overlay .palette .none .btn'] ?? '');
    }

    public function testOnPhonesTheSearchHidesTheKeyHintsAndShowsCancel(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('display: none', $rules['.overlay .palette .q kbd, .overlay .palette footer'] ?? '');
        self::assertStringContainsString('display: inline-flex', $rules['.overlay .palette .q .btn'] ?? '');
    }

    public function testOnADesktopTheSearchCancelButtonIsHidden(): void
    {
        $rules = $this->rulesOf($this->sheets('components')[0]);

        self::assertStringContainsString('display: none', $rules['.palette .q .btn'] ?? '');
    }

    public function testOnPhonesTheMoreSheetOpensAboveTheBar(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('display: block', $rules['.menu:not([hidden])'] ?? '');
        self::assertStringContainsString('bottom: calc(var(--bar) + var(--s8) + env(safe-area-inset-bottom))', $rules['.menu nav'] ?? '');
        self::assertStringContainsString('min-height: 48px', $rules['.menu a'] ?? '');
        self::assertStringContainsString('background: var(--selected)', $rules['.menu a[aria-current="page"]'] ?? '');
    }

    public function testOnPhonesTheTopBarHoldsOnlyTheLogoTheSearchButtonAndTheThemeToggle(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('display: none', $rules['.search-trigger .ph, .search-trigger kbd, .topbar .grow, .topbar .select'] ?? '');
        self::assertStringContainsString('display: inline', $rules['.search-trigger .ph-short'] ?? '');
        self::assertStringContainsString('display: flex', $rules['.topbar .brand-m'] ?? '');
    }

    public function testOnPhonesTheTopBarLeavesOutTheOfflineMarkerAndTheReconnectingLineWraps(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('display: none', $rules['.topbar .offline-marker'] ?? '');
        self::assertStringContainsString('flex-wrap: wrap', $rules['.conn'] ?? '');
    }

    public function testOnPhonesThePageEndsAboveTheBar(): void
    {
        $rules = $this->phoneRules();

        self::assertStringContainsString('calc(var(--bar) + var(--s32) + env(safe-area-inset-bottom))', $rules['.content'] ?? '');
        self::assertStringContainsString('calc(var(--bar) + var(--s16) + env(safe-area-inset-bottom))', $rules['.toast'] ?? '');
    }

    public function testTheMoreItemAndItsPageAreCurrentOnAPageInItsSection(): void
    {
        $rail = $this->railWithHistory(DashboardRoutes::SHOW_REVIEW, currentSection: 'history');

        self::assertSame('page', $rail->filter('.rail .nav.more')->attr('aria-current'));
        self::assertSame('page', $rail->filter('#more a')->attr('aria-current'));
        self::assertCount(0, $rail->filter('.rail a.nav[data-k="projects"][aria-current]'));
    }

    private function railWithHistory(string $currentRoute, ?string $currentSection = null): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        // Because History, the first page under More, is not built yet, the review list stands in for it.
        return new Crawler($twig->render('dashboard/_rail.html.twig', [
            'rail_items' => [['key' => 'projects', 'label' => 'Projects', 'icon' => 'projects', 'route' => DashboardRoutes::LIST_PROJECTS]],
            'more_items' => [['key' => 'history', 'label' => 'History', 'icon' => 'clock', 'route' => DashboardRoutes::LIST_REVIEWS]],
            'current_route' => $currentRoute,
            'current_section' => $currentSection,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function phoneRules(): array
    {
        return $this->phoneRulesOf($this->sheets('components')[0]);
    }

    /**
     * @return array<string, string>
     */
    private function phoneRulesOf(string $sheet): array
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $sheet, $blocks);

        return $this->rulesOf(implode("\n", $blocks[1]));
    }

    /**
     * @return list<string>
     */
    private function sheets(string ...$names): array
    {
        // One page first, and only one, because the client boots a fresh kernel for each request, which drops the test ledger the page reads.
        $page = $this->browser->request('GET', '/reviews');
        self::assertResponseIsSuccessful();
        $addresses = array_map(static fn (string $name): string => (string) $page->filter('link[rel="stylesheet"][href*="/'.$name.'-"]')->attr('href'), $names);

        return array_values(array_map($this->servedAsset(...), $addresses));
    }

    private function servedAsset(string $address): string
    {
        $this->browser->request('GET', $address);
        self::assertResponseIsSuccessful();

        // Read from the internal response, because the asset server answers with a BinaryFileResponse, whose getContent() is empty.
        return $this->browser->getInternalResponse()->getContent();
    }

    /**
     * @return array<string, string>
     */
    private function rulesOf(string $sheet): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }
}
