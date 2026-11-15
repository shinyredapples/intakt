<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class FirstRunStylesTest extends TestCase
{
    public function testOnAPhoneTheStepButtonRowSticksAboveTheTabBar(): void
    {
        $row = $this->rule('.step-nav', $this->phoneBlock());

        self::assertMatchesRegularExpression('/position:\s*sticky;/', $row);
        self::assertMatchesRegularExpression('/bottom:\s*calc\(var\(--bar\) \+ env\(safe-area-inset-bottom\)\);/', $row);
    }

    public function testOnAPhoneTheStepButtonRowIsDrawnBelowTheTabBar(): void
    {
        self::assertSame(1, preg_match('/z-index:\s*(\d+);/', $this->rule('.rail-in', $this->phoneBlock()), $bar));
        self::assertSame(1, preg_match('/z-index:\s*(\d+);/', $this->rule('.step-nav', $this->phoneBlock()), $row));
        self::assertGreaterThan(0, (int) $row[1]);
        self::assertLessThan((int) $bar[1], (int) $row[1]);
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
