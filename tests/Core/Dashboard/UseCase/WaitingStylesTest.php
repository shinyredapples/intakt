<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as TaskDetailStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WaitingStylesTest extends TestCase
{
    private const string BRIDGE = 'width < 1100px';
    private const string PHONE = 'max-width: 760px';
    private const string STACKED = '/grid-template-columns:\s*minmax\(0, 1fr\);\s*grid-template-areas:\s*"title" "meta" "conseq" "why" "act";/';

    // DESIGN.md's bridge (761 to 1099 px) is single column, and act after why keeps the tab order of D6.
    public function testBelow1100WideAWaitingItemIsOneColumnOfTitleMetaConsequenceWhyAndChoicesInThatOrder(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks(self::BRIDGE));

        self::assertMatchesRegularExpression(self::STACKED, $rules['.wait-item'] ?? '');
    }

    // A zoomed window has fractional CSS widths, and 1099.5 px is still below 1100.
    public function testAWaitingItemIsOneColumnAtEveryWidthBelow1100IncludingAFractionalOne(): void
    {
        $sheet = (string) preg_replace('~/\*.*?\*/~s', '', $this->sheet());
        preg_match_all('/@media \(([^)]+)\) \{(.*?)\n\}/s', $sheet, $blocks, PREG_SET_ORDER);
        $queries = array_column(array_filter($blocks, fn (array $block): bool => 1 === preg_match(self::STACKED, $this->rulesOf($block[2])['.wait-item'] ?? '')), 1);
        self::assertCount(1, $queries);

        foreach ([761.0, 1099.0, 1099.5, 1099.99, 1100.0, 1440.0] as $width) {
            self::assertSame($width < 1100.0, $this->matchesWidth($queries[0], $width), (string) $width);
        }
    }

    public function testFrom1100WideAWaitingItemKeepsItsChoicesInA340PxColumnBesideTheWords(): void
    {
        $rules = $this->rulesOf($this->sheetOutside(self::BRIDGE, self::PHONE));

        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\) 340px;\s*grid-template-areas:\s*"title act" "meta act" "conseq act" "why act";/', $rules['.wait-item'] ?? '');
    }

    public function testTheWhySummaryIsShownAtEveryWidth(): void
    {
        foreach ([$this->sheet(), $this->mediaBlocks(self::BRIDGE), $this->mediaBlocks(self::PHONE)] as $sheet) {
            self::assertDoesNotMatchRegularExpression('/display:\s*none/', $this->rulesOf($sheet)['.wait-item .why summary'] ?? '');
        }
        self::assertMatchesRegularExpression('/display:\s*inline-flex;/', $this->rulesOf($this->sheetOutside(self::BRIDGE, self::PHONE))['.wait-item .why summary'] ?? '');
    }

    public function testOnlyAt760WideAndBelowIsTheWhySummaryAtLeast44PxTall(): void
    {
        self::assertMatchesRegularExpression('/^\s*min-height:\s*44px;\s*$/', $this->rulesOf($this->mediaBlocks(self::PHONE))['.wait-item .why summary'] ?? '');
        self::assertArrayNotHasKey('.wait-item .why summary', $this->rulesOf($this->mediaBlocks(self::BRIDGE)));
        self::assertDoesNotMatchRegularExpression('/min-height/', $this->rulesOf($this->sheetOutside(self::BRIDGE, self::PHONE))['.wait-item .why summary'] ?? '');
    }

    // The template's heading level follows the page outline, so the mock's look must not depend on the tag.
    public function testTheLeadTakesTheMocksHeadingLookFromItsClass(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/grid-area:\s*title;.*font:\s*600 var\(--t17\)\/1\.3 var\(--f-head\);/s', $rules['.wait-lead'] ?? '');
        self::assertArrayNotHasKey('.wait-item h2', $rules);
        self::assertArrayNotHasKey('.wait-item h3', $rules);
    }

    public function testAWaitingItemTakesTheAmberTintOnlyAsANeedsCard(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/^\s*background:\s*var\(--amber-tint\);\s*$/', $rules['.wait-item.needs'] ?? '');
        self::assertStringNotContainsString('--amber', $rules['.wait-item'] ?? '');
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
