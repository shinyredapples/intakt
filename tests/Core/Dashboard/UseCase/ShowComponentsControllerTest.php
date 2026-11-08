<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowComponents\ShowComponentsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

// Because the stand-in scripts are created and removed in assets/vendor/ of the checkout, which every test worker shares.
#[CoversClass(ShowComponentsController::class)]
#[Group('serial')]
final class ShowComponentsControllerTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    /**
     * @return iterable<string, array{string}>
     */
    public static function componentMarkers(): iterable
    {
        yield 'button' => ['.btn.primary'];
        yield 'danger button' => ['.btn.danger[data-controller="arm"]'];
        yield 'chip' => ['.chip'];
        yield 'count' => ['.count.needs'];
        yield 'card' => ['.card'];
        yield 'needs-you card' => ['.card.needs'];
        yield 'list row' => ['.list .row'];
        yield 'setting row' => ['.srow'];
        yield 'switch' => ['.switch'];
        yield 'input' => ['input.input[aria-invalid="true"]'];
        yield 'bar' => ['.window .bar'];
        yield 'banner' => ['.banner.broken'];
        yield 'sub-tabs' => ['.subtabs'];
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

    public function testTheComponentsPageCarriesAThemeToggleWiredToTheThemeController(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertSame('theme#flip', $page->filter('[data-controller="theme"] button')->attr('data-action'));
        self::assertGreaterThan(0, $page->filter('script[type="importmap"]')->count());
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
