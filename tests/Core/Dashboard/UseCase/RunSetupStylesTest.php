<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class RunSetupStylesTest extends TestCase
{
    public function testOnAPhoneEachProgressMarkerIsALinkAtLeastTheTouchFloorHigh(): void
    {
        self::assertGreaterThanOrEqual(44, $this->pixels('/height:\s*(\d+)px;/', $this->rule('.wizard .steps a', $this->phoneBlock())));
    }

    public function testOnAPhoneTheProgressKeepsOnlyItsBarsAndTheMarkerWordsStayForScreenReaders(): void
    {
        $words = $this->rule('.wizard .steps a span', $this->phoneBlock());

        self::assertMatchesRegularExpression('/clip-path:\s*inset\(50%\);/', $words);
        self::assertDoesNotMatchRegularExpression('/display:\s*none/', $words);
    }

    public function testOnAPhoneTheStepCountShowsAt15Px(): void
    {
        $count = $this->rule('.wizard .step-count', $this->phoneBlock());

        self::assertMatchesRegularExpression('/display:\s*block;/', $count);
        self::assertMatchesRegularExpression('/font-size:\s*var\(--t15\);/', $count);
        self::assertMatchesRegularExpression('/display:\s*none;/', $this->rule('.wizard .step-count', $this->desktopRules()));
    }

    // The wizard's small text uses the 13 px step, which a phone raises to 15 px, so no text there is under 15 px.
    public function testOnAPhoneTheSmallTextStepIsRaisedTo15Px(): void
    {
        self::assertMatchesRegularExpression('/--t13:\s*15px;/', $this->file('assets/styles/tokens.css'));
        self::assertDoesNotMatchRegularExpression('/font(-size)?:[^;]*\b1[0-4]px/', $this->wizardRules());
    }

    public function testOnAPhoneEveryButtonIsAtLeastTheTouchFloorHigh(): void
    {
        self::assertMatchesRegularExpression('/min-height:\s*44px;/', $this->rule('.btn, .btn.sm, .linkbtn, .toast button', $this->phoneBlock()));
    }

    public function testOnAPhoneTheStepButtonRowSticksAboveTheTabBar(): void
    {
        $row = $this->rule('.step-nav', $this->phoneBlock());

        self::assertMatchesRegularExpression('/position:\s*sticky;/', $row);
        self::assertMatchesRegularExpression('/bottom:\s*calc\(var\(--bar\) \+ env\(safe-area-inset-bottom\)\);/', $row);
    }

    public function testOnAPhoneTheStepButtonRowIsDrawnBelowTheTabBar(): void
    {
        $row = $this->zIndex('.step-nav');

        self::assertGreaterThan(0, $row);
        self::assertLessThan($this->zIndex('.rail-in'), $row);
    }

    public function testOnAPhoneTheSharedInputRuleIsAtLeastTheTouchFloorHigh(): void
    {
        self::assertGreaterThanOrEqual(44, $this->pixels('/height:\s*(\d+)px;/', $this->rule('.input, .select', $this->phoneBlock())));
    }

    public function testAt1920TheSetupColumnIsCenteredInTheMainArea(): void
    {
        $main = 1920 - $this->pixels('/--rail:\s*(\d+)px/', $this->file('assets/styles/tokens.css'));
        $capped = min($main, $this->pixels('/max-width:\s*(\d+)px/', $this->rule('.content', $this->desktopRules())));
        $content = $this->pageLiftsTheContentCap() ? $main : $capped;

        self::assertMatchesRegularExpression('/margin:\s*0 auto;/', $this->rule('.setup', $this->desktopRules()));
        self::assertSame(intdiv($main, 2), intdiv($content, 2), 'the center of .content, which .setup is centered in, against the center of .main');
    }

    public function testAt390TheTextColumnIsTheMocks358Wide(): void
    {
        $gutter = $this->padding('.content', $this->phoneBlock())[1] + $this->padding('.setup', $this->phoneBlock())[1];

        self::assertSame(358, 390 - 2 * $gutter);
    }

    public function testTheContentAndTheColumnTogetherPadTheMocks48AboveAnd80Below(): void
    {
        $content = $this->padding('.content', $this->desktopRules());
        $column = $this->padding('.setup', $this->desktopRules());

        self::assertSame([48, 80], [$content[0] + $column[0], $content[2] + $column[2]]);
    }

    public function testOnAPhoneTheContentAndTheColumnTogetherPadTheMocks24AboveAnd48OverTheTabBar(): void
    {
        $content = $this->padding('.content', $this->phoneBlock());
        $column = $this->padding('.setup', $this->phoneBlock());

        self::assertSame([24, 48], [$content[0] + $column[0], $content[2] + $column[2]]);
    }

    public function testTheSetupMascotIsAtMost144PxHighOnDesktop(): void
    {
        self::assertMatchesRegularExpression('/max-height:\s*144px;/', $this->rule('.setup .page-head .character', $this->desktopRules()));
    }

    public function testOnAPhoneTheSetupMascotIsAtMost140PxHigh(): void
    {
        self::assertMatchesRegularExpression('/max-height:\s*140px;/', $this->rule('.setup .page-head .character', $this->phoneBlock()));
    }

    public function testTheSetupHeadKeepsTheMascotBesideTheTitleOnAPhone(): void
    {
        self::assertMatchesRegularExpression('/flex-wrap:\s*nowrap;/', $this->rule('.setup .page-head', $this->desktopRules()));
        self::assertMatchesRegularExpression('/flex:\s*1 1 0;/', $this->rule('.setup .page-head > div', $this->desktopRules()));
        self::assertMatchesRegularExpression('/min-width:\s*0;/', $this->rule('.setup .page-head > div', $this->desktopRules()));
    }

    public function testTheDashboardLoadsTheDemoSheetAsItsFifthStylesheet(): void
    {
        preg_match_all('~<link rel="stylesheet" href="\{\{ asset\(\'styles/([a-z]+)\.css\'\) \}\}">~', $this->file('templates/dashboard/base.html.twig'), $sheets);

        self::assertSame(['fonts', 'tokens', 'base', 'components', 'demo'], $sheets[1]);
    }

    public function testTheReviewStripKeepsItsFourStepsInOneRowOnAPhone(): void
    {
        self::assertMatchesRegularExpression('/grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\);/', $this->rule('.review-strip', $this->demoSheet()));
        self::assertDoesNotMatchRegularExpression('/grid-template-columns/', $this->rule('.review-strip', $this->phoneBlockOf($this->demoSheet())));
    }

    public function testTheDemoSheetUsesOnlyTokensForColors(): void
    {
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i', $this->demoSheet());
    }

    private function demoSheet(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->file('assets/styles/demo.css'));
    }

    private function phoneBlockOf(string $css): string
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $css, $blocks);

        return implode("\n", $blocks[1]);
    }

    private function zIndex(string $selector): int
    {
        return 1 === preg_match('/z-index:\s*(\d+);/', $this->rule($selector, $this->phoneBlock()), $found) ? (int) $found[1] : self::fail($selector);
    }

    // Top, side and bottom in px, counting spacing steps only, because the tab bar's height and the safe-area inset in a phone's bottom padding clear the bar and are no gap.
    /** @return array{int, int, int} */
    private function padding(string $selector, string $css): array
    {
        $padding = 1 === preg_match('/padding:\s*([^;]+);/', $this->rule($selector, $css), $found) ? $found[1] : self::fail($selector);
        preg_match_all('/(?:[^\s()]+|\((?:[^()]|\([^()]*\))*\))+/', $padding, $values);
        $steps = array_map($this->spacing(...), $values[0]);

        return [$steps[0], $steps[1] ?? $steps[0], $steps[2] ?? $steps[0]];
    }

    private function spacing(string $value): int
    {
        preg_match_all('/var\(--s(\d+)\)/', $value, $steps);

        return (int) array_sum($steps[1]);
    }

    private function file(string $path): string
    {
        $text = file_get_contents(__DIR__.'/../../../../'.$path);
        self::assertIsString($text);

        return $text;
    }

    private function pixels(string $pattern, string $text): int
    {
        return 1 === preg_match($pattern, $text, $found) ? (int) $found[1] : self::fail($pattern);
    }

    // The rules that hold at every width: a media block's rules would otherwise be read as the page's own.
    private function desktopRules(): string
    {
        return (string) preg_replace('/@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $this->sheet());
    }

    private function pageLiftsTheContentCap(): bool
    {
        $wide = str_contains($this->file('templates/dashboard/setup.html.twig'), '{% block content_width %} wide{% endblock %}');

        return $wide && 1 === preg_match('/max-width:\s*none;/', $this->rule('.content.wide', $this->desktopRules()));
    }

    private function wizardRules(): string
    {
        preg_match_all('/[^{}]*\.(?:wizard|checks)[^{}]*\{[^{}]*\}/', $this->sheet().$this->demoSheet(), $rules);

        return implode("\n", $rules[0]);
    }

    private function sheet(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->file('assets/styles/components.css'));
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
