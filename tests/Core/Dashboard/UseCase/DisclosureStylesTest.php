<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as WhoIsWorkingStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class DisclosureStylesTest extends TestCase
{
    public function testEveryDisclosureHidesTheBrowsersMarkerSoTheChevronIsItsOnlySign(): void
    {
        self::assertMatchesRegularExpression('/list-style:\s*none/', $this->rule('details > summary', $this->sheet()));
        self::assertMatchesRegularExpression('/display:\s*none/', $this->rule('details > summary::-webkit-details-marker', $this->sheet()));
    }

    public function testTheChevronOfAClosedDisclosureTurnsWithATransition(): void
    {
        self::assertMatchesRegularExpression('/transition:\s*transform/', $this->rule('details > summary .chev', $this->sheet()));
    }

    public function testTheChevronOfAnOpenDisclosureIsTurned(): void
    {
        self::assertMatchesRegularExpression('/rotate\(180deg\)/', $this->rule('details[open] > summary .chev', $this->sheet()));
    }

    public function testTheChevronDoesNotAnimateWhenTheReaderAsksForReducedMotion(): void
    {
        preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*?)\n\}/s', $this->sheet(), $block);

        self::assertStringContainsString('details > summary .chev', $block[1] ?? '');
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
    }

    private function rule(string $selector, string $css): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }
}
