<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as BoardStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class TaskDetailStylesTest extends TestCase
{
    public function testFrom761WideTheTimelineAndLiveLogSwitchIsNotShown(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.ttabs'] ?? '');
    }

    public function testOnAPhoneTheSwitchSticksUnderTheTopBarAcrossTheWholeWidth(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/display:\s*flex;\s*position:\s*sticky;\s*top:\s*var\(--top\);.*background:\s*var\(--page\);/s', $rules['.ttabs'] ?? '');
        self::assertMatchesRegularExpression('/width:\s*100%;\s*margin:\s*0;/', $rules['.ttabs .seg'] ?? '');
    }

    public function testOnAPhoneOnlyTheChosenPaneIsShown(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.task-grid[data-pane="timeline"] .log-pane, .task-grid[data-pane="log"] .tl-pane'] ?? '');
    }

    // The tab says which pane shows, so the timeline's own heading goes; a page without the switch keeps it.
    public function testOnAPhoneTheTimelineHeadingGivesWayToTheSwitchOnlyWhereThereIsOne(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*display:\s*none;\s*$/', $rules['.ttabs ~ .task-grid .timeline-title'] ?? '');
        self::assertArrayNotHasKey('.timeline-title', $rules);
    }

    // The mock puts Stop and Continue side by side and the last action across the row; Continue waits for T59, so Stop keeps its half.
    public function testOnAPhoneTheTaskActionsAreTwoEqualColumnsWithTheLastActionAcrossTheRow(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/display:\s*grid;\s*grid-template-columns:\s*1fr 1fr;.*width:\s*100%;/s', $rules['.page-head .task-actions'] ?? '');
        self::assertMatchesRegularExpression('/^\s*grid-column:\s*1 \/ -1;\s*$/', $rules['.task-actions > .start-review'] ?? '');
        self::assertMatchesRegularExpression('/^\s*width:\s*100%;\s*$/', $rules['.task-actions .btn'] ?? '');
    }

    // Needed because a one-line open finding measured 41.75 px at 390 wide in the UI check, under the 44 px floor of a touch target.
    public function testOnAPhoneAnOpenFindingLinkOfTheNowBlockIsAtLeast44PxTall(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*min-height:\s*44px;\s*$/', $rules['.sb-finding'] ?? '');
    }

    // Needed because the browser scrolled a card followed from the Now block to the very top, under the sticky top bar.
    public function testAFindingCardFollowedFromTheNowBlockStopsBelowTheTopBar(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/scroll-margin-top:\s*calc\(var\(--top\) \+ var\(--s16\)\);/', $rules['.finding-card'] ?? '');
    }

    public function testOnAPhoneAFindingCardFollowedFromTheNowBlockStopsBelowTheTimelineAndLiveLogSwitch(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*scroll-margin-top:\s*calc\(var\(--top\) \+ var\(--tab-h\) \+ 2 \* var\(--seg-pad\) \+ 2 \* var\(--ttabs-pad\) \+ var\(--s16\)\);\s*$/', $rules['.ttabs ~ .task-grid .finding-card'] ?? '');
    }

    // Needed because a size written by hand in the switch but not in the offset put a followed card under the switch again.
    public function testOnAPhoneTheSwitchTakesItsSizesFromTheValuesTheScrollOffsetAddsUp(): void
    {
        $phone = $this->rulesOf($this->mediaBlocks('max-width: 760px'));
        $wide = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/height:\s*var\(--tab-h\);/', $phone['.seg button'] ?? '');
        self::assertMatchesRegularExpression('/padding:\s*var\(--seg-pad\);/', $wide['.seg'] ?? '');
        self::assertMatchesRegularExpression('/padding:\s*var\(--ttabs-pad\) var\(--s16\);/', $phone['.ttabs'] ?? '');
    }

    public function testTheStylesheetHidesTheStartOfARoundWhileARoundIsInReview(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../../assets/styles/components.css');

        self::assertStringContainsString('.task-actions:has(.now-runs) > .start-review { display: none; }', $css);
    }

    public function testFrom1500WideTheLogSitsBesideAWiderTimelineAndScrollsWithin520Px(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('min-width: 1500px'));

        self::assertMatchesRegularExpression('/^\s*max-width:\s*1720px;\s*$/', $rules['.content.roomy'] ?? '');
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 960px\) minmax\(440px, 1fr\);/', $rules['.task-grid'] ?? '');
        self::assertMatchesRegularExpression('/^\s*max-height:\s*520px;\s*$/', $rules['.log-main'] ?? '');
    }

    public function testFrom2200WideTheSummaryIsA360PxColumnBesideALogOfUpTo640Px(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('min-width: 2200px'));

        self::assertMatchesRegularExpression('/^\s*max-width:\s*2000px;\s*$/', $rules['.content.roomy'] ?? '');
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 900px\) minmax\(0, 1fr\);/', $rules['.task-grid'] ?? '');
        self::assertMatchesRegularExpression('/display:\s*grid;\s*grid-template-columns:\s*minmax\(0, 1fr\) 360px;\s*grid-template-rows:\s*auto 1fr;/', $rules['.log'] ?? '');
        self::assertMatchesRegularExpression('/^\s*grid-column:\s*1 \/ -1;\s*$/', $rules['.log > h2'] ?? '');
        self::assertMatchesRegularExpression('/^\s*max-height:\s*640px;\s*$/', $rules['.log-main'] ?? '');
        self::assertMatchesRegularExpression('/border-top:\s*0;.*border-left:\s*1px solid var\(--hairline\);/s', $rules['.log-summary'] ?? '');
        self::assertMatchesRegularExpression('/^\s*grid-template-columns:\s*1fr;\s*$/', $rules['.ls-grid'] ?? '');
    }

    // They must wrap, because DESIGN.md's Long titles never clip a title, sha or path, so on a 390 px screen it would run off the edge.
    public function testALongTitleAChangeNameOrALoggedPathWrapsInsteadOfOverflowing(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/min-width:\s*0;.*overflow-wrap:\s*anywhere;/s', $rules['.page-head > div:first-child'] ?? '');
        self::assertMatchesRegularExpression('/white-space:\s*normal;\s*overflow-wrap:\s*anywhere;/', $rules['.page-head .meta span'] ?? '');
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere;/', $rules['.live-calls'] ?? '');
    }

    // The mock puts Stop at the top beside the title, so the title column takes the rest of the row instead of pushing the actions under the review path.
    public function testTheTaskActionsStandAtTheTopBesideATitleColumnThatTakesTheRestOfTheRow(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*align-items:\s*flex-start;\s*$/', $rules['.page-head:has(> .task-actions)'] ?? '');
        self::assertMatchesRegularExpression('/^\s*flex:\s*1 1 280px;\s*$/', $rules['.page-head:has(> .task-actions) > div:first-child'] ?? '');
    }

    // It must start at the top, because the browser's own top margin of the title put Stop about 25 px above the title text at 1440 wide.
    public function testTheTitleBesideTheTaskActionsHasNoTopMarginSoStopSitsOnTheTitlesRow(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*margin-top:\s*0;\s*$/', $rules['.page-head:has(> .task-actions) h1'] ?? '');
    }

    // It must shrink, because at 390 wide a long change name moved to a line of its own and left its "/" alone at the end of the line before.
    public function testThePagesOwnCrumbShrinksOnTheTrailsLineSoALongChangeNameWrapsThere(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*flex:\s*1 1 12ch;\s*$/', $rules['.crumbs li:last-child'] ?? '');
        self::assertMatchesRegularExpression('/min-width:\s*0;.*overflow-wrap:\s*anywhere;/s', $rules['.crumbs li'] ?? '');
    }

    // It must stay whole, because at 390 wide the Full record's "estimated" label broke into "estimate" and "d" beside the cost.
    public function testAFiguresKindLabelInTheHeadsFactsNeverBreaksInsideItsWord(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*white-space:\s*nowrap;\s*overflow-wrap:\s*normal;\s*$/', $rules['.page-head .meta .figure-kind'] ?? '');
    }

    // One text, because as a flex row the figure, its kind label and the price date each shrank to a narrow column of its own at 390 wide.
    public function testTheCostInTheHeadsFactsFlowsAsOneTextInsteadOfAColumnPerPart(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*display:\s*inline;\s*$/', $rules['.page-head .meta #review-summary-cost'] ?? '');
    }

    // At 390 wide `anywhere` let the reviewer table shrink until "correctness" broke into three lines; `break-word` keeps words whole and the scroll box takes the width.
    public function testACellOfARoundTableKeepsItsWordsWholeAndTheTableScrolls(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*overflow-wrap:\s*break-word;\s*$/', $rules['.round .ledger td'] ?? '');
        self::assertMatchesRegularExpression('/overflow-x:\s*auto;/', $rules['.table-scroll'] ?? '');
    }

    public function testAJudgedRoundsFindingsAreOneColumnOfCardsWhoseTextWrapsInsteadOfOverflowing(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 760px'));

        self::assertMatchesRegularExpression('/display:\s*grid;/', $rules['.finding-cards'] ?? '');
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere;/', $rules['.finding-card'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--selected\);/', $rules['.finding-card.selected'] ?? '');
    }

    // Beside the chip the text would lose the chip's width on a 390 px screen, so it goes under it.
    public function testOnAPhoneAFindingCardsTextTakesTheCardsWholeWidthUnderTheChip(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 760px'));

        self::assertMatchesRegularExpression('/^\s*flex-wrap:\s*wrap;\s*$/', $rules['.finding-card'] ?? '');
        self::assertMatchesRegularExpression('/^\s*flex-basis:\s*100%;\s*$/', $rules['.fc-body'] ?? '');
    }

    public function testTheSummarysCostPutsEachFigureUnderItsLabelInsteadOfBesideIt(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/grid-template-columns:\s*1fr;/', $rules['.ls-cost'] ?? '');
        self::assertMatchesRegularExpression('/text-align:\s*left;/', $rules['.ls-cost dd'] ?? '');
    }

    public function testACostFigureWrapsAtWordBoundariesAndNeverLetterByLetter(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/overflow-wrap:\s*break-word;/', $rules['.ls-cost dd'] ?? '');
        self::assertDoesNotMatchRegularExpression('/anywhere/', ($rules['.ls-cost dd'] ?? '').($rules['.ls-cost dt'] ?? ''));
    }

    public function testALabelInTheSummaryKeepsAReadableMinimumWidth(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(8ch,\s*1fr\)\s+auto;/', $rules['.ls-dl'] ?? '');
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return $sheet;
    }

    private function mediaBlocks(string $query): string
    {
        preg_match_all('/@media \('.preg_quote($query, '/').'\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return [] === $blocks[1] ? self::fail($query) : implode("\n", $blocks[1]);
    }

    private function sheetOutside(string $query): string
    {
        preg_match_all('/@media \('.preg_quote($query, '/').'\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return str_replace($blocks[0], '', $this->sheet());
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
