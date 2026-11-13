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
