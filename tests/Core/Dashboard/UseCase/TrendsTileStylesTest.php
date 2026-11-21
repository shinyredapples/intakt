<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as WaitingStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class TrendsTileStylesTest extends TestCase
{
    // A tip anchored at its label's left edge runs past a phone's right edge from the third tile of a row (review of #3198).
    public function testATilesTooltipIsAnchoredToTheTileGridNotToItsLabel(): void
    {
        $rules = $this->rulesOf($this->sheetOutsideThePhoneRules());

        self::assertMatchesRegularExpression('/position:\s*relative;/', $rules['.tiles'] ?? '');
        self::assertMatchesRegularExpression('/position:\s*static;/', $rules['.tile .term'] ?? '');
    }

    public function testATilesTooltipStartsAtTheGridsLeftEdgeAndIsNeverWiderThanTheGrid(): void
    {
        $rules = $this->rulesOf($this->sheetOutsideThePhoneRules());

        self::assertMatchesRegularExpression('/left:\s*0;/', $rules['.tile .term-tip'] ?? '');
        self::assertMatchesRegularExpression('/max-width:\s*100%;/', $rules['.tile .term-tip'] ?? '');
    }

    public function testNoRuleForAPhoneMovesATilesLabelBackIntoAPositionedBox(): void
    {
        $phoneRules = $this->rulesOf(implode("\n", $this->phoneBlocks()[1]));

        self::assertDoesNotMatchRegularExpression('/position:/', $phoneRules['.tile .term'] ?? '');
        self::assertDoesNotMatchRegularExpression('/position:|left:|max-width:/', $phoneRules['.tile .term-tip'] ?? '');
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return $sheet;
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function phoneBlocks(): array
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return [$blocks[0], $blocks[1]];
    }

    private function sheetOutsideThePhoneRules(): string
    {
        return str_replace($this->phoneBlocks()[0], '', $this->sheet());
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
