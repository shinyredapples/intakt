<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as DisclosureStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class ChartStylesTest extends TestCase
{
    public function testEveryMarkOfAChartTakesThePrimaryHue(): void
    {
        self::assertMatchesRegularExpression('/fill:\s*var\(--primary\)/', $this->rule('.c-bar'));
    }

    public function testTheGridIsAHairline(): void
    {
        self::assertMatchesRegularExpression('/stroke:\s*var\(--hairline\);\s*stroke-width:\s*1;/', $this->rule('.c-grid'));
    }

    public function testChartsStackOnceARowCannotHoldTwo(): void
    {
        self::assertStringContainsString('repeat(auto-fit, minmax(min(100%, 360px), 1fr))', $this->rule('.charts'));
    }

    public function testAChartShrinksToItsColumnAndKeepsItsProportions(): void
    {
        self::assertMatchesRegularExpression('/max-width:\s*100%;\s*height:\s*auto;/', $this->rule('.chart-svg'));
    }

    public function testAFocusedBarShowsAVisibleRing(): void
    {
        self::assertMatchesRegularExpression('/stroke:\s*var\(--primary\);\s*stroke-width:\s*2;/', $this->rule('.c-hit:focus-visible'));
    }

    public function testTheTooltipLetsThePointerThroughToTheBars(): void
    {
        self::assertStringContainsString('pointer-events: none', $this->rule('.chart-tip'));
    }

    public function testOnPhonesShowAsTableIsATarget44PxTall(): void
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        self::assertStringContainsString('.c-table summary { min-height: 44px; }', implode("\n", $blocks[1]));
    }

    private function rule(string $selector): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->sheet(), $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
    }
}
