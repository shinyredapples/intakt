<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as TaskDetailStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class WaitingStylesTest extends TestCase
{
    // Needed because a 340 px choices column at 761 px beside the open rail left the lead about 45 px, and DESIGN.md's bridge is single column.
    public function testBelow1100WideAWaitingItemIsOneColumnInTheOrderTabReachesItsParts(): void
    {
        $rules = $this->rulesOf($this->mediaBlocks('max-width: 1099px'));

        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\);\s*grid-template-areas:\s*"title" "meta" "conseq" "why" "act";\s*grid-template-rows:\s*none;/', $rules['.wait-item'] ?? '');
        self::assertMatchesRegularExpression('/^\s*padding-left:\s*0;\s*$/', $rules['.wait-item > .meta, .wait-item .why, .wait-item .conseq'] ?? '');
    }

    public function testFrom1100WideAWaitingItemKeepsItsChoicesColumnBesideTheText(): void
    {
        $rules = $this->rulesOf($this->sheetOutside('max-width: 1099px', 'max-width: 760px'));

        self::assertMatchesRegularExpression('/grid-template-columns:\s*minmax\(0, 1fr\) 340px;\s*grid-template-areas:\s*"title act" "meta act" "conseq act" "why act";/', $rules['.wait-item'] ?? '');
    }

    public function testThePhoneFloorsOfTheWhySummaryApplyOnlyAt760WideAndBelow(): void
    {
        $phone = $this->rulesOf($this->mediaBlocks('max-width: 760px'));
        $bridge = $this->rulesOf($this->mediaBlocks('max-width: 1099px'));

        self::assertMatchesRegularExpression('/min-height:\s*44px;\s*font-size:\s*var\(--t15\);/', $phone['.wait-item .why summary'] ?? '');
        self::assertArrayNotHasKey('.wait-item .why summary', $bridge);
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
