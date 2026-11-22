<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WhoIsWorkingStylesTest extends TestCase
{
    public function testTheStatusLineIsAtLeast44PxTallAndAtLeast48OnAPhone(): void
    {
        self::assertMatchesRegularExpression('/min-height:\s*44px/', $this->rule('.status-line', $this->sheet()));
        self::assertMatchesRegularExpression('/min-height:\s*48px/', $this->rule('.status-line', $this->phoneBlock()));
    }

    public function testTheLaneTextUsesTheFifteenPixelTokenAtLeast(): void
    {
        self::assertMatchesRegularExpression('/font-size:\s*var\(--t15\)/', $this->rule('.lane .what', $this->sheet()));
        self::assertMatchesRegularExpression('/font:\s*500 var\(--t13\)/', $this->rule('.lane .who', $this->sheet()));
    }

    public function testOnAPhoneTheLanesStackInOneColumn(): void
    {
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\);/', $this->rule('.lanes', $this->phoneBlock()));
    }

    public function testALaneKeepsItsRoleLineInsideItsColumnAboveThePhoneBreakpoint(): void
    {
        $who = $this->rule('.lane .who', $this->sheet());

        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap/', $who);
        self::assertMatchesRegularExpression('/min-width:\s*0/', $who);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $who);
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\)/', $this->rule('.lane', $this->sheet()));
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $this->rule('.lane .meta span', $this->sheet()));
    }

    public function testTheChevronTurnsWhenTheSummaryIsOpen(): void
    {
        self::assertMatchesRegularExpression('/rotate\(180deg\)/', $this->rule('details[open] > summary .chev', $this->sheet()));
    }

    // It is the Status page's plain line, not a lane card or the board's status line, so it takes neither's tint nor hover.
    public function testTheNobodyIsWorkingLineIsMutedWithoutTintAtTheStatusLinesHeight(): void
    {
        $rule = $this->rule('.working-none', $this->sheet());

        self::assertMatchesRegularExpression('/min-height:\s*44px;.*color:\s*var\(--muted\)/s', $rule);
        self::assertStringNotContainsString('background', $rule);
        self::assertStringNotContainsString('.working-none:hover', $this->sheet());
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
    }

    private function phoneBlock(): string
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return implode("\n", $blocks[1]);
    }

    private function rule(string $selector, string $css): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }
}
