<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The floor of 3:1 is because WCAG 2.2 asks it of a focus indicator: https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast
#[CoversNothing]
final class FrontPageFocusContrastTest extends TestCase
{
    private const string FRONT_PAGE = __DIR__.'/../../docs/pages';
    private const float LEAST_CONTRAST = 3.0;
    private const array THEME_ROOTS = ['light' => [':root', '[data-theme="light"]'], 'dark' => [':root', '[data-theme="light"]', '[data-theme="dark"]']];
    private const array FOOTER_FOCUS = ['light' => ['footer :focus-visible'], 'dark' => ['footer :focus-visible', '[data-theme="dark"] footer :focus-visible']];

    public function testTheFooterFocusOutlineStandsOutOnTheLightFooter(): void
    {
        [$outline, $footer] = $this->footerColors('light');

        $contrast = $this->contrast($outline, $footer);

        self::assertGreaterThanOrEqual(self::LEAST_CONTRAST, $contrast);
    }

    public function testTheFooterFocusOutlineStandsOutOnTheDarkFooter(): void
    {
        [$outline, $footer] = $this->footerColors('dark');

        $contrast = $this->contrast($outline, $footer);

        self::assertGreaterThanOrEqual(self::LEAST_CONTRAST, $contrast);
    }

    /**
     * @return array{string, string}
     */
    private function footerColors(string $theme): array
    {
        $rules = $this->rules();
        $variables = $this->declared($rules, self::THEME_ROOTS[$theme]);

        return [
            $this->resolved($variables, $this->declared($rules, self::FOOTER_FOCUS[$theme], 'outline-color'), $theme),
            $this->resolved($variables, $this->declared($rules, ['footer'], 'background'), $theme),
        ];
    }

    /**
     * @return list<array{selectors: list<string>, declarations: array<string, string>}>
     */
    private function rules(): array
    {
        // Because the page links site.css before its own style element, rules read in this order win as in the browser.
        $page = HTMLDocument::createFromFile(self::FRONT_PAGE.'/index.html');
        $css = file_get_contents(self::FRONT_PAGE.'/assets/site.css').$page->querySelector('head style')?->textContent;
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $css), $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [
            'selectors' => array_map(trim(...), explode(',', $match[1])),
            'declarations' => self::declarations($match[2]),
        ], $matches);
    }

    /**
     * @return array<string, string>
     */
    private static function declarations(string $block): array
    {
        preg_match_all('/([\w-]+)\s*:\s*([^;]+)/', $block, $pairs, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $pair): array => [trim($pair[1]), trim($pair[2])], $pairs), 1, 0);
    }

    /**
     * @param array<string, string> $variables
     */
    private function resolved(array $variables, string $value, string $theme): string
    {
        while (1 === preg_match('/^var\((--[\w-]+)\)$/', $value, $name)) {
            self::assertArrayHasKey($name[1], $variables, $theme.' theme defines '.$name[1]);
            $value = $variables[$name[1]];
        }

        self::assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $value, $value.' in the '.$theme.' theme is an opaque color');

        return $value;
    }

    /**
     * @param list<array{selectors: list<string>, declarations: array<string, string>}> $rules
     * @param list<string>                                                              $selectors
     *
     * @return ($property is null ? array<string, string> : string)
     */
    private function declared(array $rules, array $selectors, ?string $property = null): array|string
    {
        $declarations = [];

        foreach ($rules as $rule) {
            if ([] !== array_intersect($rule['selectors'], $selectors)) {
                $declarations = [...$declarations, ...$rule['declarations']];
            }
        }

        if (null === $property) {
            return $declarations;
        }

        self::assertArrayHasKey($property, $declarations, implode(', ', $selectors).' sets '.$property);

        return $declarations[$property];
    }

    private function contrast(string $foreground, string $background): float
    {
        $lighter = max($this->luminance($foreground), $this->luminance($background));
        $darker = min($this->luminance($foreground), $this->luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
