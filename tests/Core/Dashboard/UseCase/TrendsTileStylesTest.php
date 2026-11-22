<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as WaitingStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class TrendsTileStylesTest extends TestCase
{
    // Because a tip hung from the grid opens below its last row, off a phone's screen while its focused label is visible (review of #3198).
    public function testATilesTooltipHangsFromItsOwnTileNotFromTheGrid(): void
    {
        $rules = $this->rulesOf($this->sheetOutsideThePhoneRules());

        self::assertMatchesRegularExpression('/position:\s*relative;/', $rules['.tile'] ?? '');
        self::assertDoesNotMatchRegularExpression('/position:/', $rules['.tiles'] ?? '');
        self::assertMatchesRegularExpression('/position:\s*static;/', $rules['.tile .term'] ?? '');
    }

    public function testOutsideAPhoneATilesTooltipSpansExactlyItsTile(): void
    {
        $tip = $this->rulesOf($this->sheetOutsideThePhoneRules())['.tile .term-tip'] ?? '';

        self::assertMatchesRegularExpression('/left:\s*0;/', $tip);
        self::assertMatchesRegularExpression('/right:\s*0;/', $tip);
        self::assertMatchesRegularExpression('/width:\s*auto;/', $tip);
        self::assertMatchesRegularExpression('/max-width:\s*none;/', $tip);
    }

    public function testOnAPhoneATilesTooltipSpansTwoColumnsAndTheGapBetweenThem(): void
    {
        $phoneRules = $this->rulesOf(implode("\n", $this->phoneBlocks()[1]));

        self::assertStringContainsString('width: calc(200% + '.$this->phoneGap().');', $phoneRules['.tile .term-tip'] ?? '');
        self::assertMatchesRegularExpression('/right:\s*auto;/', $phoneRules['.tile .term-tip'] ?? '');
    }

    public function testOnAPhoneTheMiddleTilesTooltipIsCenteredOnItSoItStaysInsideTheGrid(): void
    {
        $phoneRules = $this->rulesOf(implode("\n", $this->phoneBlocks()[1]));

        self::assertStringContainsString('left: calc(-50% - '.$this->phoneGap().' / 2);', $phoneRules['.tile:nth-child(3n+2) .term-tip'] ?? '');
    }

    public function testOnAPhoneTheLastTileOfARowOpensItsTooltipToTheLeft(): void
    {
        $tip = $this->rulesOf(implode("\n", $this->phoneBlocks()[1]))['.tile:nth-child(3n) .term-tip'] ?? '';

        self::assertMatchesRegularExpression('/left:\s*auto;/', $tip);
        self::assertMatchesRegularExpression('/right:\s*0;/', $tip);
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function phoneBlocks(): array
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return [$blocks[0], $blocks[1]];
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return $sheet;
    }

    private function phoneGap(): string
    {
        $grid = $this->rulesOf(implode("\n", $this->phoneBlocks()[1]))['.tiles'] ?? '';
        if (1 !== preg_match('/gap:\s*(var\(--s\d+\));/', $grid, $gap)) {
            self::fail('The phone grid names no gap.');
        }

        return $gap[1];
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
