<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as ComponentStylesTest does for the shared rules, because no page is needed to see a rule.
#[CoversNothing]
final class BoardStylesTest extends TestCase
{
    public function testTheBoardColumnsShareTheWidthAndKeepTheMocksMinimumWidth(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/flex:\s*1 1 0;\s*min-width:\s*176px/', $rules['.board .col'] ?? '');
    }

    // Asserted, because an absolute .sr-only label of a folded strip escaped the static scroller and widened the page at 1440 (#2659).
    public function testTheBoardScrollerHoldsItsScreenReaderLabelsSoTheyNeverWidenThePage(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/overflow-x:\s*auto;.*position:\s*relative/s', $rules['.board'] ?? '');
    }

    public function testTheWaitingOnYouColumnTakesTheMocksLargerShareAndMinimumWidth(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*flex-grow:\s*1\.25;\s*min-width:\s*224px;\s*$/', $rules['.board .col.col-needs'] ?? '');
    }

    public function testOutsideACollapsedColumnTheStripIsNotShown(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.col-fold'] ?? '');
    }

    public function testFrom761WideACollapsedColumnIsAStripOfTheMocks108Px(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('min-width: 761px'));

        self::assertMatchesRegularExpression('/^\s*flex:\s*0 0 108px;\s*min-width:\s*108px;\s*$/', $rules['.board .col.collapsed'] ?? '');
        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.col.collapsed .col-head, .col.collapsed .col-cards'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*flex;.*justify-content:\s*space-between;.*width:\s*108px;\s*height:\s*32px;.*border-radius:\s*var\(--r-chip\);\s*background:\s*var\(--tint\)/s', $rules['.col.collapsed .col-fold'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--tint-2\)/', $rules['.col.collapsed .col-fold:hover'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--raised\)/', $rules['.col.collapsed .col-fold .count'] ?? '');
    }

    public function testHideSitsAtTheEndOfTheColumnHeadAtTheMocksHeight(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*margin-left:\s*auto;\s*height:\s*28px;\s*$/', $rules['.col-hide'] ?? '');
    }

    // DESIGN.md: Backlog and Done are not collapsed on phones, so their cards stay open and Hide has nothing to do.
    public function testOnAPhoneAColumnIsNeverCollapsedAndOffersNoHide(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.col-hide'] ?? '');
        self::assertArrayNotHasKey('.board .col.collapsed', $this->rulesOf($this->sheetOutside('min-width: 761px')));
    }

    // Asserted, because DESIGN.md (Phone patterns) asks for one column at a time that a swipe snaps column by column.
    public function testOnAPhoneTheBoardShowsOneColumnAtATimeAndSnapsByColumn(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('max-width: 760px'));

        self::assertMatchesRegularExpression('/scroll-snap-type:\s*x mandatory/', $rules['.board'] ?? '');
        self::assertMatchesRegularExpression('/^\s*flex:\s*0 0 100%;\s*min-width:\s*0;\s*scroll-snap-align:\s*start;\s*$/', $rules['.board .col, .board .col.col-needs'] ?? '');
    }

    // Sticky, because the switcher must stay in reach while a long column scrolls; 44 px, because that is the phone floor of DESIGN.md.
    public function testOnAPhoneTheColumnSwitcherIsStickyWith44PxButtonsAndOnlyThere(): void
    {
        $phone = $this->rulesOf($this->mediaBlock('max-width: 760px'));

        self::assertMatchesRegularExpression('/display:\s*flex;.*position:\s*sticky;\s*top:\s*var\(--top\)/s', $phone['.colswitch'] ?? '');
        self::assertMatchesRegularExpression('/height:\s*44px/', $phone['.colswitch button'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--selected\)/', $phone['.colswitch button[aria-pressed="true"]'] ?? '');
        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $this->rulesOf($this->sheetOutside('max-width: 760px'))['.colswitch'] ?? '');
        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $phone['.stagemap'] ?? '');
    }

    // No amber, because DESIGN.md keeps amber for what waits on the owner; the map marks the columns in view in green.
    public function testTheColumnMapMarksColumnsInViewInGreenWithoutAmber(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/background:\s*var\(--primary\)/', $rules['.sm-seg.in::before'] ?? '');
        self::assertMatchesRegularExpression('/height:\s*8px/', $rules['.sm-seg.in::before'] ?? '');
        // An out-of-view column's bar is a thinner --muted line, because the mock's --tint-2 stood out from the page by under 3:1 (WCAG 1.4.11).
        self::assertMatchesRegularExpression('/width:\s*18px;\s*height:\s*4px/', $rules['.sm-seg::before'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--muted\)/', $rules['.sm-seg::before'] ?? '');
        foreach (['.stagemap', '.sm-seg', '.sm-seg::before', '.sm-seg.in::before'] as $selector) {
            self::assertStringNotContainsString('amber', $rules[$selector] ?? '');
        }
    }

    // WCAG 1.4.11 asks 3:1 of the bar, because it is the segment's only visible cue.
    public function testTheColumnMapBarsStandOutFromThePageInTheLightTheme(): void
    {
        $this->assertMapBarsStandOutFromThePage(':root, [data-theme="light"]');
    }

    public function testTheColumnMapBarsStandOutFromThePageInTheDarkTheme(): void
    {
        $this->assertMapBarsStandOutFromThePage('[data-theme="dark"]');
    }

    // WCAG 2.5.8 asks for 24 by 24 px; the segments sit side by side, so spacing cannot make up for a narrower one.
    public function testEachColumnMapSegmentIsAtLeast24PxWideAndHigh(): void
    {
        $segment = $this->rulesOf($this->sheetOutside('max-width: 760px'))['.sm-seg'] ?? '';

        self::assertMatchesRegularExpression('/(?<![-\w])width:\s*24px/', $segment);
        self::assertMatchesRegularExpression('/(?<![-\w])height:\s*24px/', $segment);
    }

    public function testTheFirstDayPanelStacksItsOctopusAboveItsTextAtMost144PxHigh(): void
    {
        self::assertMatchesRegularExpression('/\n\.firstday \{[^}]*flex-direction:\s*column;/', $this->sheet());
        self::assertMatchesRegularExpression('/\n\.firstday > \.character \{\s*max-height:\s*144px;\s*width:\s*auto;/', $this->sheet());
    }

    public function testOnAPhoneTheFirstDayPanelsOctopusIsAtMost120PxHigh(): void
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);
        $rules = $this->rulesOf(implode("\n", $blocks[1]));

        self::assertMatchesRegularExpression('/max-height:\s*120px;/', $rules['.firstday > .character'] ?? '');
    }

    // The first-day headline is a link alone in its line, so no sentence exempts it from the phone's 44 px touch target (DESIGN.md).
    public function testOnAPhoneTheFirstDayHeadlineLinkIsAtLeast44PxTall(): void
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);
        $rules = $this->rulesOf(implode("\n", $blocks[1]));

        self::assertMatchesRegularExpression('/^\s*display:\s*inline-block;\s*min-height:\s*44px;\s*$/', $rules['.page-head .lead .headline-setup'] ?? '');
    }

    // The running headline is a link alone in its line too, since the line no longer goes on to what waits (UX2 home, #3326).
    public function testOnAPhoneTheRunningHeadlineLinkIsAtLeast44PxTall(): void
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);
        $rules = $this->rulesOf(implode("\n", $blocks[1]));

        self::assertMatchesRegularExpression('/^\s*display:\s*inline-block;\s*min-height:\s*44px;\s*$/', $rules['.page-head .lead .headline-run'] ?? '');
    }

    public function testAt1700WideAndUpThePagePaddingGrowsTo48(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('min-width: 1700px'));

        self::assertMatchesRegularExpression('/padding:\s*var\(--s32\) var\(--s48\)/', $rules['.content'] ?? '');
        self::assertMatchesRegularExpression('/padding:\s*0 var\(--s48\)/', $rules['.topbar'] ?? '');
    }

    // The mock caps only its roomy pages from 2200 wide; its board (content wide) keeps growing at every width.
    public function testTheBoardContentIsNeverCappedAtAnyWidth(): void
    {
        preg_match_all('/(?:^|[{}])\s*\.content\.wide\s*\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $this->sheet()), $declarations);

        self::assertNotEmpty($declarations[1]);
        foreach ($declarations[1] as $declaration) {
            self::assertMatchesRegularExpression('/^\s*max-width:\s*none;\s*$/', $declaration);
        }
    }

    public function testACardMetaLineUsesTheMutedThirteenTypeToken(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/color:\s*var\(--muted\);\s*font-size:\s*var\(--t13\)/', $rules['.meta'] ?? '');
    }

    // A board card's cost reads "Cost unknown, no price for <model>", wider than a 176 px card, so one line ran past its edge.
    public function testAFactWiderThanACardWrapsInsideTheCard(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/white-space:\s*nowrap;/', $rules['.meta span'] ?? '');
        self::assertMatchesRegularExpression('/^\s*white-space:\s*normal;\s*$/', $rules['.card .meta span'] ?? '');
    }

    // A card's meta line shows its spans on one line (.meta span), which would otherwise outrank the tip's own rule.
    public function testAGlossaryTipInACardsMetaLineStaysHiddenAndWrapsUntilItsTermIsPointedAt(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertArrayNotHasKey('.term-tip', $rules);
        self::assertMatchesRegularExpression('/display:\s*none;/', $rules['.term .term-tip'] ?? '');
        self::assertMatchesRegularExpression('/white-space:\s*normal;/', $rules['.term .term-tip'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*block/', $rules['.term:hover .term-tip, .term:focus .term-tip'] ?? '');
    }

    // A card's round is a term the keyboard reaches on its own, so bin/ui-check measured it as a 22 px touch target at 390 wide.
    public function testOnAPhoneATermOnAMetaLineIsAtLeast44PxTall(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('max-width: 760px'));

        self::assertMatchesRegularExpression('/min-height:\s*44px/', $rules['.meta .term'] ?? '');
    }

    public function testTheCountBadgeInAColumnHeadIsStyledFromTokens(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/border-radius:\s*var\(--r-round\);\s*background:\s*var\(--tint\);\s*color:\s*var\(--muted\);\s*font:\s*600 var\(--t13\)/', $rules['.count'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--amber\)/', $rules['.count.needs'] ?? '');
    }

    // Because DESIGN.md (Awkward data and states) clamps long titles to three lines and truncates project names at 60%.
    public function testACardClampsALongTitleToThreeLinesAndTruncatesALongProjectName(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/-webkit-line-clamp:\s*3;\s*overflow:\s*hidden;\s*overflow-wrap:\s*anywhere;/', $rules['.card .title'] ?? '');
        self::assertMatchesRegularExpression('/max-width:\s*60%;\s*overflow:\s*hidden;\s*text-overflow:\s*ellipsis;\s*white-space:\s*nowrap;/', $rules['.card .top .pname'] ?? '');
    }

    // Because a change name as long as a branch wrapped over nine lines in the corner and squeezed the project name to one letter.
    public function testACardTruncatesALongNumberAndLeavesTheProjectNameItsShare(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*min-width:\s*0;\s*overflow:\s*hidden;\s*text-overflow:\s*ellipsis;\s*white-space:\s*nowrap;\s*$/', $rules['.card .top .num'] ?? '');
        self::assertMatchesRegularExpression('/flex:\s*none;/', $rules['.card .top .pname'] ?? '');
    }

    // Because a Replay chip beside the change and project squeezed the change to zero width in a 176 px phone column.
    public function testACardWrapsItsReplayChipBelowTheChangeInsteadOfSqueezingTheChange(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/display:\s*flex;\s*flex-wrap:\s*wrap;/', $rules['.card .top'] ?? '');
    }

    public function testACardPastAColumnsPageLimitIsNotShown(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.col-cards > [data-past-limit]'] ?? '');
    }

    // A button's own display would otherwise show it once every card is out.
    public function testShowMoreTakesTheColumnsWidthAndHidesWhenItHasNothingToShow(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*width:\s*100%;\s*$/', $rules['.more-btn'] ?? '');
        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.more-btn[hidden]'] ?? '');
    }

    // The mock's 64 px on the right and 48 px on the left, so the fade matches docs/design/mocks/assets/intakt.css.
    public function testTheSideOfTheBoardWithHiddenColumnsFadesAsInTheMock(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('min-width: 761px'));

        self::assertMatchesRegularExpression('/^\s*-webkit-mask-image:\s*(linear-gradient\(to right, var\(--ink\) calc\(100% - 64px\), transparent\));\s*mask-image:\s*\1;\s*$/', $rules['.board-wrap.more-right .board'] ?? '');
        self::assertMatchesRegularExpression('/^\s*-webkit-mask-image:\s*(linear-gradient\(to right, transparent, var\(--ink\) 48px, var\(--ink\) calc\(100% - 64px\), transparent\));\s*mask-image:\s*\1;\s*$/', $rules['.board-wrap.more-left.more-right .board'] ?? '');
        self::assertMatchesRegularExpression('/^\s*-webkit-mask-image:\s*(linear-gradient\(to right, transparent, var\(--ink\) 48px\));\s*mask-image:\s*\1;\s*$/', $rules['.board-wrap.more-left:not(.more-right) .board'] ?? '');
    }

    // DESIGN.md's phone board snaps column by column, so a fade would hide part of the one column it shows.
    public function testBelow761WideTheBoardNeverFades(): void
    {
        self::assertStringNotContainsString('mask-image', $this->sheetOutside('min-width: 761px'));
    }

    private function assertMapBarsStandOutFromThePage(string $theme): void
    {
        $tokens = file_get_contents(__DIR__.'/../../../../assets/styles/tokens.css');
        self::assertIsString($tokens);
        $colors = $this->rulesOf($tokens)[$theme] ?? '';
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        foreach (['.sm-seg::before', '.sm-seg.in::before'] as $bar) {
            preg_match('/background:\s*var\((--[\w-]+)\)/', $rules[$bar] ?? '', $token);

            self::assertGreaterThanOrEqual(3.0, $this->contrast($this->colorOf($colors, $token[1] ?? self::fail($bar)), $this->colorOf($colors, '--page')), $bar);
        }
    }

    private function colorOf(string $tokens, string $name): string
    {
        preg_match('/(?<![\w-])'.preg_quote($name, '/').':\s*(#[0-9A-Fa-f]{6})\s*;/', $tokens, $color);

        return $color[1] ?? self::fail($name);
    }

    private function contrast(string $one, string $other): float
    {
        $luminances = [$this->luminance($one), $this->luminance($other)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    private function luminance(string $color): float
    {
        $linear = array_map(static function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return $sheet;
    }

    private function mediaBlock(string $query): string
    {
        preg_match('/@media \('.preg_quote($query, '/').'\) \{(.*?)\n\}/s', $this->sheet(), $block);

        return $block[1] ?? self::fail($query);
    }

    private function sheetOutside(string $query): string
    {
        return str_replace($this->mediaBlock($query), '', $this->sheet());
    }

    /**
     * @return array<string, string>
     */
    private function rulesOf(string $sheet): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }
}
