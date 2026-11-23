<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Uri\Rfc3986\Uri;

#[CoversNothing]
final class DashboardLayoutTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string TOKENS_TABLE = __DIR__.'/../../../../docs/design/mocks/DESIGN.md';

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'the project list' => ['/projects'];
        yield 'the review list' => ['/reviews'];
        yield 'a running review' => ['/reviews/round-1'];
        yield 'the components page' => ['/components'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesOtherThanTheComponentsPage(): iterable
    {
        yield 'the project list' => ['/projects'];
        yield 'the review list' => ['/reviews'];
        yield 'a running review' => ['/reviews/round-1'];
    }

    #[DataProvider('pages')]
    public function testEveryPageLoadsNoFontScriptOrStyleFromAnotherHost(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();

        foreach ($this->loadedAddressesOf($page) as $address) {
            self::assertMatchesRegularExpression('#^/(?!/)#', $address);
        }
        self::assertStringNotContainsString('@import', $page->html());
        self::assertDoesNotMatchRegularExpression('#https?://#i', $page->html());
        self::assertDoesNotMatchRegularExpression('#url\(\s*["\']?(https?:)?//#i', $page->html());
    }

    #[DataProvider('pagesOtherThanTheComponentsPage')]
    public function testNoPageOtherThanTheComponentsPageRendersAForcedState(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $page->filter('.is-hover, .is-focus, .is-active')->count());
    }

    public function testTheComponentsPageRendersForcedStates(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $page->filter('.is-hover, .is-focus, .is-active')->count());
    }

    // Without it a phone lays the page out at a desktop width, so the 390 px rules of DESIGN.md never apply.
    #[DataProvider('pages')]
    public function testEveryPageSetsTheDeviceWidthViewport(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $page = $this->browser->request('GET', $path);

        self::assertSame('width=device-width, initial-scale=1', $page->filter('head meta[name="viewport"]')->attr('content'));
    }

    public function testEveryFontOfTheLayoutIsServedByTheInstall(): void
    {
        $fonts = $this->fontAddressesOf($this->layout());

        self::assertCount(10, $fonts);
        foreach ($fonts as $font) {
            self::assertMatchesRegularExpression('#^/assets/fonts/[a-z-]+/[a-z0-9-]+-[a-zA-Z0-9_-]+\.woff2$#', $font);
            $this->browser->request('GET', $font);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'font/woff2');
        }
    }

    public function testTheThemeIsSetInTheHeadBeforeAnyStylesheet(): void
    {
        $head = $this->layout()->filter('head')->html();

        $script = strpos($head, '<script>');
        self::assertNotFalse($script);
        self::assertLessThan(strpos($head, 'rel="stylesheet"'), $script);
    }

    public function testTheTokenSheetHoldsEveryTokenOfTheDesignForBothThemes(): void
    {
        $sheet = $this->stylesheet('tokens');

        foreach ($this->designTokens() as [$token, $light, $dark]) {
            self::assertStringContainsString($token.': '.$light.';', $this->themeBlockOf($sheet, 'light'), $token);
            self::assertStringContainsString($token.': '.$dark.';', $this->themeBlockOf($sheet, 'dark'), $token);
        }
        self::assertStringContainsString('--link: var(--deep);', $this->themeBlockOf($sheet, 'light'));
        self::assertStringContainsString('--link: var(--primary);', $this->themeBlockOf($sheet, 'dark'));
        self::assertStringContainsString('--on-btn: #FFFFFF;', $this->themeBlockOf($sheet, 'light'));
        self::assertStringContainsString('--on-btn: #121816;', $this->themeBlockOf($sheet, 'dark'));
    }

    private function layout(): Crawler
    {
        $page = $this->browser->request('GET', '/reviews');
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * @return list<string>
     */
    private function loadedAddressesOf(Crawler $page): array
    {
        $addresses = [
            ...$page->filter('link[href]')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
            ...$page->filter('script[src], img[src], source[src], iframe[src]')->each(static fn (Crawler $element): string => (string) $element->attr('src')),
        ];
        self::assertNotEmpty($addresses);

        return $addresses;
    }

    /**
     * @return list<string>
     */
    private function fontAddressesOf(Crawler $page): array
    {
        $fonts = [];
        foreach ($page->filter('link[rel="stylesheet"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')) as $sheet) {
            preg_match_all('/url\("?([^")]+)"?\)/', $this->servedAsset($sheet), $urls);
            $base = new Uri('http://localhost:7780'.$sheet);
            $fonts = [...$fonts, ...array_map(static fn (string $url): string => new Uri($url, $base)->getPath(), $urls[1])];
        }

        return $fonts;
    }

    private function stylesheet(string $name): string
    {
        $href = $this->layout()->filter('link[rel="stylesheet"][href*="/'.$name.'-"]')->attr('href');

        return $this->servedAsset((string) $href);
    }

    private function servedAsset(string $address): string
    {
        $this->browser->request('GET', $address);
        self::assertResponseIsSuccessful();

        // Read from the internal response, because the asset server answers with a BinaryFileResponse, whose getContent() is empty.
        return $this->browser->getInternalResponse()->getContent();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function designTokens(): array
    {
        preg_match_all('/^\| `(--[a-z0-9-]+)` \| (#[0-9A-F]{6}) \| (#[0-9A-F]{6}) \|/m', (string) file_get_contents(self::TOKENS_TABLE), $rows, PREG_SET_ORDER);
        self::assertCount(13, $rows);

        return array_map(static fn (array $row): array => [$row[1], $row[2], $row[3]], $rows);
    }

    private function themeBlockOf(string $sheet, string $theme): string
    {
        self::assertSame(1, preg_match('/\[data-theme="'.$theme.'"\]\s*\{([^}]*)\}/', $sheet, $block));

        return $block[1];
    }
}
