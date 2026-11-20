<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\BarChart;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartPoint;
use Intakt\Core\Dashboard\UseCase\ShowComponents\ShowComponentsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowComponentsController::class)]
#[UsesClass(BarChart::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartPoint::class)]
final class ShowComponentsControllerTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    /**
     * @return iterable<string, array{string}>
     */
    public static function componentMarkers(): iterable
    {
        yield 'button' => ['.btn.primary'];
        yield 'danger button' => ['.btn.danger[data-arm-confirm]'];
        yield 'chip' => ['.chip'];
        yield 'approved outcome chip' => ['.chip.approved svg.i'];
        yield 'count' => ['.count.needs'];
        yield 'card' => ['.card'];
        yield 'needs-you card' => ['.card.needs'];
        yield 'list row' => ['.list .row'];
        yield 'setting row' => ['.srow'];
        yield 'switch' => ['.switch'];
        yield 'input' => ['input.input[aria-invalid="true"]'];
        yield 'stepper' => ['.stepper[role="group"][aria-label] button + output + button'];
        yield 'copy field' => ['.copy code + button.btn'];
        yield 'bar' => ['.window .bar'];
        yield 'last-known bar' => ['.window .bar.last-known'];
        yield 'bar chart' => ['.charts .chart svg.chart-svg path.c-bar + rect.c-hit'];
        yield 'banner' => ['.banner.broken'];
        yield 'compact banner, collapsed' => ['.banner.compact:not(.open) .banner-toggle[aria-expanded="false"]'];
        yield 'compact banner, open' => ['.banner.compact.open .banner-toggle[aria-expanded="true"]'];
        yield 'reconnecting line' => ['.conn:not(.unreachable):not([hidden]) button.btn'];
        yield 'unreachable line' => ['.conn.unreachable:not([hidden])'];
        yield 'offline marker' => ['.offline-marker:not([hidden])'];
        yield 'icon button' => ['.icon-btn[aria-label]'];
        yield 'sub-tabs' => ['.subtabs'];
        yield 'segmented tabs' => ['.seg[role="tablist"][aria-label] [role="tab"][aria-selected="true"]'];
        yield 'filter chips' => ['.fchip[aria-pressed="true"] + .fchip[aria-pressed="false"]'];
        yield 'forced primary hover' => ['.btn.primary.is-hover'];
        yield 'forced primary focus' => ['.btn.primary.is-focus'];
        yield 'forced primary active' => ['.btn.primary.is-active'];
        yield 'forced secondary active' => ['.btn.secondary.is-active'];
        yield 'forced quiet hover' => ['.btn.quiet.is-hover'];
        yield 'forced danger hover' => ['.btn.danger.is-hover'];
        yield 'forced needs focus' => ['.btn.needs.is-focus'];
        yield 'forced icon button focus' => ['.icon-btn.is-focus[aria-label]'];
        yield 'forced card hover' => ['a.card.is-hover'];
        yield 'forced card focus' => ['a.card.is-focus'];
        yield 'forced input focus' => ['input.input.is-focus[aria-label]'];
        yield 'forced sub-tab focus' => ['.subtabs a.is-focus'];
        yield 'search results' => ['.palette.static [role="group"] > .grp svg.i'];
        yield 'selected search result' => ['.palette.static .res[role="option"][aria-selected="true"]'];
        yield 'no search results' => ['.palette.static .none button[data-search-action="clear-search"]'];
        yield 'toast' => ['.toast'];
        yield 'skeleton' => ['.skel-card'];
        yield 'empty state' => ['.empty'];
        yield 'error line' => ['.test-result.err'];
    }

    #[DataProvider('componentMarkers')]
    public function testTheComponentsPageShowsEachComponentInBothThemes(string $selector): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
        foreach (['light', 'dark'] as $theme) {
            self::assertGreaterThan(0, $page->filter('.spec[data-theme="'.$theme.'"] '.$selector)->count(), $theme.' '.$selector);
        }
    }

    public function testTheComponentsPageShowsTheFourOutcomeChipsInBothThemes(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
        foreach (['light', 'dark'] as $theme) {
            self::assertSame(
                ['Approved', 'Changes requested', 'Blocked', 'Failed'],
                $page->filter('.spec[data-theme="'.$theme.'"] .outcomes .chip')->each(static fn (Crawler $chip): string => $chip->text()),
                $theme,
            );
        }
    }

    public function testTheComponentsPageShowsEveryCharacterAndPoseNextToItsNameInBothThemes(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
        foreach (['light', 'dark'] as $theme) {
            $section = $page->filter('#c-characters .spec[data-theme="'.$theme.'"]');
            self::assertSame(
                ['Code reviewer: Meerkat with a magnifying glass', 'Judge: Owl with a gavel', 'Waiting', 'Conducting', 'Bowing', 'Asleep'],
                $section->filter('.character-row')->each(static fn (Crawler $row): string => $row->text()),
                $theme,
            );
            self::assertCount(2 * (2 * 2 + 4), $section->filter('img.character[alt=""]'), $theme);
        }
    }

    public function testTheComponentsPageFocusesTheBlockedCardOfTheMockInEachTheme(): void
    {
        $page = $this->browser->request('GET', '/components');

        foreach (['light', 'dark'] as $theme) {
            $focused = $page->filter('#c-cards .spec[data-theme="'.$theme.'"] a.card.is-focus');
            self::assertCount(1, $focused, $theme);
            self::assertStringContainsString('#626', $focused->text(), $theme);
        }
    }

    public function testEachChartsTableListsTheSameNumbersAsItsBarsInBothThemes(): void
    {
        $page = $this->browser->request('GET', '/components');

        foreach (['light', 'dark'] as $theme) {
            $charts = $page->filter('#c-charts .spec[data-theme="'.$theme.'"] .charts > .chart');
            self::assertCount(2, $charts, $theme);
            $charts->each(static function (Crawler $chart) use ($theme): void {
                $rows = $chart->filter('.c-table tbody tr')->each(static fn (Crawler $row): string => $row->filter('th')->text().': '.$row->filter('td')->text());
                self::assertNotEmpty($rows, $theme);
                self::assertSame($rows, $chart->filter('svg rect.c-hit')->each(static fn (Crawler $hit): string => (string) $hit->attr('aria-label')), $theme);
            });
        }
    }

    public function testAMissingDayDrawsNoBarAndReadsNoDataNeverZero(): void
    {
        $chart = $this->browser->request('GET', '/components')->filter('#chart-rounds-light');

        self::assertCount(14, $chart->filter('rect.c-hit'));
        self::assertCount(13, $chart->filter('path.c-bar'));
        self::assertCount(1, $chart->filter('rect.c-hit[aria-label="Nov 5: No data"][data-reading="No data"]'));
        self::assertSame('No data', $chart->filter('.c-table tbody tr')->eq(4)->filter('td')->text());
    }

    public function testAZeroDrawsABarSoItReadsApartFromMissingData(): void
    {
        $chart = $this->browser->request('GET', '/components')->filter('#chart-findings-dark');

        self::assertCount(6, $chart->filter('path.c-bar'));
        self::assertCount(1, $chart->filter('rect.c-hit[aria-label="Round 4: 0"]'));
    }

    public function testTheKeyboardReachesEveryBarAndItsFocusShowsTheTooltip(): void
    {
        $page = $this->browser->request('GET', '/components');

        $hits = $page->filter('#c-charts rect.c-hit');
        self::assertCount(2 * (14 + 6), $hits);
        $hits->each(static function (Crawler $hit): void {
            self::assertSame('0', $hit->attr('tabindex'));
            self::assertSame('img', $hit->attr('role'));
            self::assertSame('pointerenter->chart#pointerEnter pointerleave->chart#pointerLeave focus->chart#focusIn blur->chart#focusOut', $hit->attr('data-action'));
        });
    }

    public function testEachChartCarriesItsTooltipControllerAndAHiddenTip(): void
    {
        $page = $this->browser->request('GET', '/components');

        $charts = $page->filter('#c-charts .chart');
        self::assertCount(4, $charts);
        self::assertCount(4, $page->filter('#c-charts .chart[data-controller="chart"][data-action="keydown.esc@window->chart#dismiss"]'));
        self::assertCount(4, $page->filter('#c-charts .chart > .chart-tip[data-chart-target="tip"][aria-hidden="true"][hidden] > strong[data-chart-target="reading"] + span[data-chart-target="label"]'));
        self::assertCount(4, $page->filter('#c-charts .chart > details.c-table > summary'));
        self::assertSame('Show as table', $page->filter('#c-charts .c-table summary')->first()->text());
    }

    public function testEachChartNamesItsSvgByItsTitleAndSaysItsTakeaway(): void
    {
        $chart = $this->browser->request('GET', '/components')->filter('#c-charts .spec[data-theme="dark"] .chart')->first();

        self::assertSame('Review rounds per day', $chart->filter('#chart-rounds-dark-title')->text());
        self::assertSame('chart-rounds-dark-title', $chart->filter('svg')->attr('aria-labelledby'));
        self::assertSame('48 rounds in 14 days, at most 6 a day; Nov 5 has no record.', $chart->filter('.takeaway')->text());
    }

    public function testTheComponentsPageAnswersASecondRequestOfTheSameTest(): void
    {
        $this->browser->request('GET', '/components');
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
    }

    public function testTheComponentsPageCarriesAThemeToggleBesideTheOneOfTheTopBar(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertSame('Switch theme', $page->filter('.page-head button[data-theme-toggle]')->text());
        self::assertCount(1, $page->filter('.topbar button[data-theme-toggle]'));
        self::assertGreaterThan(0, $page->filter('script[type="importmap"]')->count());
    }

    public function testEveryResultOfTheSearchExampleSitsInAListBox(): void
    {
        $page = $this->browser->request('GET', '/components');

        $options = $page->filter('#c-search [role="option"]');
        self::assertGreaterThan(0, $options->count());
        self::assertCount($options->count(), $page->filter('#c-search [role="listbox"][aria-label] [role="option"]'));
    }

    public function testEveryControlOfTheComponentsPageHasAnAccessibleName(): void
    {
        $page = $this->browser->request('GET', '/components');

        $controls = $page->filter('input, select, textarea, button');
        self::assertGreaterThan(0, $controls->count());
        $controls->each(static function (Crawler $control) use ($page): void {
            $id = (string) $control->attr('id');
            $labelled = '' !== $id && $page->filter('label[for="'.$id.'"]')->count() > 0;
            $named = $labelled || '' !== (string) $control->attr('aria-label') || ('button' === $control->nodeName() && '' !== trim($control->text()));
            self::assertTrue($named, 'unnamed control '.$id);
        });
    }

    public function testAnInputWithoutAVisibleLabelIsNamedByItsLabel(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertSame('Default', $page->filter('#in-default-light')->attr('aria-label'));
        self::assertSame('On', $page->filter('#in-on-dark')->attr('aria-label'));
    }

    public function testAControlWithAVisibleLabelGetsNoSecondName(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertNull($page->filter('#set-default-light')->attr('aria-label'));
        self::assertCount(1, $page->filter('label[for="set-default-light"]'));
    }

    public function testNoIdOfTheComponentsPageIsUsedTwice(): void
    {
        $ids = $this->browser->request('GET', '/components')->filter('[id]')->each(static fn ($element): string => (string) $element->attr('id'));

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testTheComponentsPageSetsNoRawColorSpacingOrSizeInline(): void
    {
        $page = $this->browser->request('GET', '/components');

        foreach ($page->filter('[style]')->each(static fn ($element): string => (string) $element->attr('style')) as $style) {
            self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(|font-size|padding|margin|gap/i', $style);
        }
    }
}
