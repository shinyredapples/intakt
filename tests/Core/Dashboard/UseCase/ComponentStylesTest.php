<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversNothing]
final class ComponentStylesTest extends DashboardPageTestCase
{
    private const string GALLERY = __DIR__.'/component-gallery.html.twig';

    public function testTheLayoutServesTheComponentSheet(): void
    {
        self::assertStringContainsString('.btn.danger[data-armed]', $this->componentSheet());
    }

    public function testTheComponentSheetUsesNoRawColor(): void
    {
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|oklch)\(|:\s*(?:white|black)\b/i', $this->componentSheet());
    }

    public function testTheComponentSheetTakesEveryTypeSizeFromATypeToken(): void
    {
        preg_match_all('/font(?:-size)?:\s*([^;}]+)/', $this->componentSheet(), $declarations);

        self::assertNotEmpty($declarations[1]);
        foreach ($declarations[1] as $declaration) {
            self::assertDoesNotMatchRegularExpression('/\d(?:px|rem|em|pt)\b/', $declaration, $declaration);
        }
    }

    public function testAmberAppearsOnlyInNeedsYouRules(): void
    {
        $amberSelectors = array_keys(array_filter($this->rulesOf($this->componentSheet()), static fn (string $body): bool => str_contains($body, '--amber')));

        self::assertNotEmpty($amberSelectors);
        foreach ($amberSelectors as $selector) {
            foreach (explode(',', $selector) as $part) {
                self::assertStringContainsString('.needs', $part, $selector);
            }
        }
    }

    public function testEveryClassTheComponentsRenderIsStyled(): void
    {
        [$components, $base] = $this->sheets('components', 'base');

        foreach ($this->renderedClasses() as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $components.$base, $class);
        }
    }

    public function testTheComponentsThatNeedScriptNameTheirControllers(): void
    {
        $controllers = $this->gallery()->filter('#gallery [data-controller]')->each(static fn (Crawler $element): string => (string) $element->attr('data-controller'));

        self::assertSame(['arm', 'disclosure', 'disclosure', 'toast'], $controllers);
    }

    public function testASwitchInputFillsItsTrackWithExplicitDimensions(): void
    {
        // A checkbox is a replaced box with a natural size: inset alone leaves it about 13 px square, so the track needs explicit width and height.
        $sheet = $this->componentSheet();
        $rules = $this->rulesOf(substr($sheet, 0, (int) strpos($sheet, '@media (max-width: 760px)')));

        self::assertMatchesRegularExpression('/width:\s*100%;\s*height:\s*100%/', $rules['.switch input'] ?? '');
    }

    public function testOnPhonesASwitchIs52By32WithAHitArea44PxTall(): void
    {
        preg_match('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->componentSheet(), $phone);
        $rules = $this->rulesOf($phone[1] ?? '');

        self::assertMatchesRegularExpression('/width:\s*52px;\s*height:\s*32px/', $rules['.switch'] ?? '');
        self::assertMatchesRegularExpression('/top:\s*-6px;\s*width:\s*100%;\s*height:\s*44px/', $rules['.switch input'] ?? '');
    }

    private function componentSheet(): string
    {
        return $this->sheets('components')[0];
    }

    /**
     * @return list<string>
     */
    private function sheets(string ...$names): array
    {
        // One page first, because the client boots a fresh kernel for each request, which drops the test ledger the page reads.
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

    /**
     * @return list<string>
     */
    private function renderedClasses(): array
    {
        $classes = $this->gallery()->filter('#gallery [class]')->each(static fn (Crawler $element): array => explode(' ', trim((string) $element->attr('class'))));
        $classes = array_values(array_unique(array_merge(...$classes)));
        self::assertNotEmpty($classes);

        return $classes;
    }

    private function gallery(): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->createTemplate((string) file_get_contents(self::GALLERY))->render());
    }
}
