<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WhoIsWorkingStylesTest extends TestCase
{
    public function testTheSummaryIsAtLeast44PxTallAndAtLeast48OnAPhone(): void
    {
        self::assertMatchesRegularExpression('/min-height:\s*44px/', $this->rule('.working summary', $this->sheet()));
        self::assertMatchesRegularExpression('/min-height:\s*48px/', $this->rule('.working summary', $this->phoneBlock()));
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

    public function testTheChevronTurnsWhenTheSummaryIsOpen(): void
    {
        self::assertMatchesRegularExpression('/rotate\(180deg\)/', $this->rule('.working[open] .chev', $this->sheet()));
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
