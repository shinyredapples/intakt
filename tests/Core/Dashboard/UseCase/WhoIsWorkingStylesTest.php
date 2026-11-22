<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WhoIsWorkingStylesTest extends TestCase
{
    public function testAWorkingCardTitleUsesTheSeventeenPixelTokenAndWrapsLongNames(): void
    {
        $title = $this->rule('.wcard-title', $this->sheet());

        self::assertMatchesRegularExpression('/font:\s*600 var\(--t17\)/', $title);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $title);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $this->rule('.wcard .meta span', $this->sheet()));
    }

    public function testOnAPhoneTheWorkingCardsStackInOneColumn(): void
    {
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\);/', $this->rule('.w-cards', $this->phoneBlock()));
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

    public function testTheChevronTurnsWhenTheSummaryIsOpen(): void
    {
        self::assertMatchesRegularExpression('/rotate\(180deg\)/', $this->rule('details[open] > summary .chev', $this->sheet()));
    }

    // A tinted line with a hover would look like a card that opens, which it is not.
    public function testTheNobodyIsWorkingLineIsMutedWithoutTint(): void
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
