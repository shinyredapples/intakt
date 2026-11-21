<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as FirstRunStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class TourCaptionStylesTest extends TestCase
{
    private const string SHOWN = ':root:has(.tour-caption):not([data-tour="closed"])';

    public function testOnAPhoneTheCaptionAndTheTabBarTogetherTakeAtMost100PxAboveTheSafeArea(): void
    {
        $caption = $this->pixels('/min-height:\s*(\d+)px;/', $this->rule('.tour-caption'));
        $bar = $this->pixels('/--bar:\s*(\d+)px;/', $this->rule(self::SHOWN));

        self::assertLessThanOrEqual(100, $caption + $bar);
    }

    public function testOnAPhoneTheCaptionsCloseButtonAndTheTabsKeepTheir44PxTarget(): void
    {
        self::assertGreaterThanOrEqual(44, $this->pixels('/min-height:\s*(\d+)px;/', $this->rule('.tour-caption')));
        self::assertGreaterThanOrEqual(44, $this->pixels('/height:\s*(\d+)px;/', $this->rule(self::SHOWN.' .rail .nav')));
    }

    public function testTheTabsStillFitTheShorterBar(): void
    {
        $tab = $this->pixels('/height:\s*(\d+)px;/', $this->rule(self::SHOWN.' .rail .nav'));

        // 2 * 4, because the tab bar pads its tabs with --s4 (4 px) above and below, so this number follows that padding when it changes.
        self::assertLessThanOrEqual($this->pixels('/--bar:\s*(\d+)px;/', $this->rule(self::SHOWN)) - 2 * 4, $tab);
    }

    private function pixels(string $pattern, string $text): int
    {
        return 1 === preg_match($pattern, $text, $found) ? (int) $found[1] : self::fail($pattern);
    }

    private function rule(string $selector): string
    {
        $text = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($text);
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', (string) preg_replace('~/\*.*?\*/~s', '', $text), $blocks);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', implode("\n", $blocks[1]), $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }
}
