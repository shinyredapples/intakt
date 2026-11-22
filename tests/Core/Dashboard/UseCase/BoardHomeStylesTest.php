<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The Needs you and Working now rules of the UX2 home (#3326), read as text like BoardStylesTest, because no page is needed to see a rule.
#[CoversNothing]
final class BoardHomeStylesTest extends TestCase
{
    // DESIGN.md, Rules: no all-caps or tracked labels.
    public function testTheNeedsYouKickerIsNeitherUppercaseNorTracked(): void
    {
        $kicker = $this->rule('.needs-you .kicker', $this->sheet());

        self::assertStringNotContainsString('text-transform', $kicker);
        self::assertStringNotContainsString('letter-spacing', $kicker);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homeRules(): iterable
    {
        foreach (['.needs-you', '.needs-you .kicker', '.needs-you .decisions', '.w-count', '.wcard-title', '.path li', '.path li::before', '.path li::after', '.path li.done::after', '.path li.now::after'] as $selector) {
            yield $selector => [$selector];
        }
    }

    // A colour from a theme token follows the dark theme, a raw one would stay light.
    #[DataProvider('homeRules')]
    public function testEveryColourOfTheHomeCardsComesFromAThemeToken(string $selector): void
    {
        $rule = $this->rule($selector, $this->sheet());
        preg_match_all('/(?:^|;)\s*(?:color|background|border(?:-color)?|box-shadow):\s*([^;]+)/', $rule, $values);

        foreach ($values[1] as $value) {
            self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|oklch)\(|\b(?:white|black)\b/i', $value, $selector);
        }
    }

    public function testTheNeedsYouCardTakesTheAmberTintAndInkFromTheTheme(): void
    {
        $card = $this->rule('.needs-you', $this->sheet());

        self::assertMatchesRegularExpression('/background:\s*var\(--amber-tint\)/', $card);
        self::assertMatchesRegularExpression('/color:\s*var\(--ink\)/', $card);
        self::assertMatchesRegularExpression('/border:\s*1px solid color-mix\(in srgb, var\(--amber\)/', $card);
    }

    public function testAWorkingCardTitleUsesTheSeventeenPixelTokenAndWrapsLongNames(): void
    {
        $title = $this->rule('.wcard-title', $this->sheet());

        self::assertMatchesRegularExpression('/font:\s*600 var\(--t17\)/', $title);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $title);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $this->rule('.wcard .meta span', $this->sheet()));
    }

    public function testOnAPhoneTheWorkingCardsStackInOneColumn(): void
    {
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\);/', $this->rule('.w-cards', $this->phoneBlocks()));
    }

    public function testTheCurrentStepOfThePathHasTheAmberRingAndABoldLabel(): void
    {
        self::assertMatchesRegularExpression('/border-color:\s*var\(--amber\)/', $this->rule('.path li.now::after', $this->sheet()));
        self::assertMatchesRegularExpression('/font-weight:\s*700/', $this->rule('.path li.now', $this->sheet()));
    }

    public function testTheRoleFadesInOnItsStepOnlyWithoutAReducedMotionPreference(): void
    {
        self::assertSame(1, preg_match('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.path \.who \{ animation:/', $this->sheet()));
    }

    public function testTheBoardOctopusStepsAsideWhileNeedsYouConducts(): void
    {
        self::assertMatchesRegularExpression('/display:\s*none/', $this->rule('.needs-you ~ #board-octopus .character, .needs-you ~ #board-octopus.asleep', $this->sheet()));
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
    }

    private function phoneBlocks(): string
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
