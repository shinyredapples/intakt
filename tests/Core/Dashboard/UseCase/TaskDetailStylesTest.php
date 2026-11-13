<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The review page's layout at each width of DESIGN.md's Breakpoints and Phone patterns, read from the sheet as text as BoardStylesTest does.
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

    // DESIGN.md, Long titles: page titles wrap, and shas and paths wrap rather than run off a 390 px screen.
    public function testALongTitleAChangeNameOrALoggedPathWrapsInsteadOfOverflowing(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/min-width:\s*0;.*overflow-wrap:\s*anywhere;/s', $rules['.page-head > div:first-child'] ?? '');
        self::assertMatchesRegularExpression('/white-space:\s*normal;\s*overflow-wrap:\s*anywhere;/', $rules['.page-head .meta span'] ?? '');
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere;/', $rules['.live-calls'] ?? '');
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
