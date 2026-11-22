<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as TaskDetailStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WaitingStylesTest extends TestCase
{
    private const string WIDE = 'width >= 1100px';
    private const string PHONE = 'max-width: 760px';
    private const string BESIDE = '/grid-template-columns:\s*48px minmax\(0, 1fr\) 340px;\s*grid-template-areas:\s*"char title act" "char meta act" "conseq conseq act";/';

    // Below 1100 px the reactions come last in one column, so Tab reaches them after the words.
    public function testBelow1100WideAWaitingItemStacksTheRoleTitleMetaConsequenceAndReactionsInThatOrder(): void
    {
        $rules = $this->rulesOf($this->sheetOutside(self::WIDE));

        self::assertMatchesRegularExpression('/grid-template-columns:\s*48px minmax\(0, 1fr\);\s*grid-template-areas:\s*"char title" "char meta" "conseq conseq" "act act";/', $rules['.wait-item'] ?? '');
    }

    // Fractional widths are checked because a zoomed window has them.
    public function testTheReactionsSitBesideTheWordsOnlyFrom1100WideIncludingAFractionalWidthBelow(): void
    {
        $sheet = (string) preg_replace('~/\*.*?\*/~s', '', $this->sheet());
        preg_match_all('/@media \(([^)]+)\) \{(.*?)\n\}/s', $sheet, $blocks, PREG_SET_ORDER);
        $queries = array_column(array_filter($blocks, fn (array $block): bool => 1 === preg_match(self::BESIDE, $this->rulesOf($block[2])['.wait-item'] ?? '')), 1);
        self::assertSame([self::WIDE], $queries);

        foreach ([761.0, 1099.0, 1099.5, 1099.99, 1100.0, 1440.0] as $width) {
            self::assertSame($width >= 1100.0, $this->matchesWidth($queries[0], $width), (string) $width);
        }
    }

    // At least 44 px, because that is the smallest touch target the design allows.
    public function testAReactionIsAtLeast44PxTallAtEveryWidth(): void
    {
        self::assertMatchesRegularExpression('/min-height:\s*56px;/', $this->rulesOf($this->sheet())['.wait-act'] ?? '');
        self::assertArrayNotHasKey('.wait-act', $this->rulesOf($this->mediaBlocks(self::WIDE)));
    }

    // No disclosure on an overview page (UX2): the Why is gone, so no rule styles one.
    public function testNoWaitingRuleStylesADisclosure(): void
    {
        foreach (array_keys($this->rulesOf($this->sheet())) as $selector) {
            if (str_contains($selector, 'wait')) {
                self::assertDoesNotMatchRegularExpression('/\.why|summary|details|\.copy/', $selector);
            }
        }
    }

    // The template's heading level follows the page outline, so the look must not depend on the tag.
    public function testTheLeadTakesItsHeadingLookFromItsClass(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/grid-area:\s*title;.*font:\s*600 var\(--t17\)\/1\.3 var\(--f-head\);/s', $rules['.wait-lead'] ?? '');
        self::assertArrayNotHasKey('.wait-item h2', $rules);
        self::assertArrayNotHasKey('.wait-item h3', $rules);
    }

    public function testTheHeroUsesANeutralSurfaceNeverTheAmberTintAndANeedsCardTakesAnAmberEdge(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertStringNotContainsString('amber-tint', $rules['.wait-hero.needs'] ?? '');
        self::assertStringContainsString('background: var(--raised);', $rules['.wait-hero.needs'] ?? '');
        self::assertStringNotContainsString('--amber', $rules['.wait-hero.needs'] ?? '');
        self::assertStringContainsString('border: 1px solid var(--hairline);', $rules['.wait-hero.needs'] ?? '');
        self::assertMatchesRegularExpression('/^\s*border-color:\s*color-mix\(in srgb, var\(--amber\) 60%, transparent\);\s*$/', $rules['.wait-item.needs'] ?? '');
        self::assertStringNotContainsString('--amber', $rules['.wait-item'] ?? '');
    }

    // Because DESIGN.md (Breakpoints) puts "Meanwhile" and "You handled today" beside the list only from 1500 px.
    public function testBelow1500WideThePanelsStackUnderTheList(): void
    {
        self::assertMatchesRegularExpression('/^\s*display:\s*grid;\s*grid-template-columns:\s*minmax\(0, 1fr\);/', $this->rulesOf($this->sheetOutside('min-width: 1500px', 'min-width: 2200px'))['.split'] ?? '');
    }

    public function testFrom1500WideThePanelsSitBesideTheListInA380PxColumn(): void
    {
        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\) 380px;/', $this->rulesOf($this->mediaBlocks('min-width: 1500px'))['.split'] ?? '');
        self::assertMatchesRegularExpression('/position:\s*sticky;/', $this->rulesOf($this->mediaBlocks('min-width: 1500px'))['.split > .side'] ?? '');
    }

    // Because DESIGN.md (Phone) wants every touch target at least 44 px tall.
    public function testOnlyAt760WideAndBelowIsAPanelRow48PxAndItsLink44PxTall(): void
    {
        $phone = $this->rulesOf($this->mediaBlocks(self::PHONE));

        self::assertMatchesRegularExpression('/min-height:\s*48px;/', $phone['.split > .side li'] ?? '');
        self::assertMatchesRegularExpression('/min-height:\s*44px;/', $phone['.split > .side li a'] ?? '');
        self::assertArrayNotHasKey('.split > .side li a', $this->rulesOf($this->sheetOutside(self::PHONE)));
    }

    // The board's row keeps its own .side, so the panels' rules reach only the aside of the split.
    public function testThePanelRulesReachOnlyTheAsideOfTheSplit(): void
    {
        $selectors = array_keys($this->rulesOf($this->sheet()));

        self::assertSame([], array_values(array_filter($selectors, static fn (string $selector): bool => 1 === preg_match('/(^|,\s*)\.side\b/', $selector))));
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

    private function matchesWidth(string $query, float $width): bool
    {
        return match (true) {
            1 === preg_match('/^max-width: (\d+(?:\.\d+)?)px$/', $query, $limit) => $width <= (float) $limit[1],
            1 === preg_match('/^width < (\d+(?:\.\d+)?)px$/', $query, $limit) => $width < (float) $limit[1],
            1 === preg_match('/^width >= (\d+(?:\.\d+)?)px$/', $query, $limit) => $width >= (float) $limit[1],
            1 === preg_match('/^width <= (\d+(?:\.\d+)?)px$/', $query, $limit) => $width <= (float) $limit[1],
            default => self::fail($query),
        };
    }

    private function sheetOutside(string ...$queries): string
    {
        $sheet = $this->sheet();
        foreach ($queries as $query) {
            preg_match_all('/@media \('.preg_quote($query, '/').'\) \{(.*?)\n\}/s', $sheet, $blocks);
            $sheet = str_replace($blocks[0], '', $sheet);
        }

        return $sheet;
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
