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

    public function testAt1700WideAndUpThePagePaddingGrowsTo48(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('min-width: 1700px'));

        self::assertMatchesRegularExpression('/padding:\s*var\(--s32\) var\(--s48\)/', $rules['.content'] ?? '');
        self::assertMatchesRegularExpression('/padding:\s*0 var\(--s48\)/', $rules['.topbar'] ?? '');
    }

    public function testAt2200WideAndUpTheBoardContentStopsAt2000(): void
    {
        $rules = $this->rulesOf($this->mediaBlock('min-width: 2200px'));

        self::assertMatchesRegularExpression('/max-width:\s*2000px/', $rules['.content.wide'] ?? '');
    }

    public function testACardMetaLineUsesTheMutedThirteenTypeToken(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/color:\s*var\(--muted\);\s*font-size:\s*var\(--t13\)/', $rules['.meta'] ?? '');
    }

    public function testTheCountBadgeInAColumnHeadIsStyledFromTokens(): void
    {
        $rules = $this->rulesOf($this->sheet());

        self::assertMatchesRegularExpression('/border-radius:\s*var\(--r-round\);\s*background:\s*var\(--tint\);\s*color:\s*var\(--muted\);\s*font:\s*600 var\(--t13\)/', $rules['.count'] ?? '');
        self::assertMatchesRegularExpression('/background:\s*var\(--amber\)/', $rules['.count.needs'] ?? '');
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

    /**
     * @return array<string, string>
     */
    private function rulesOf(string $sheet): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $rule): array => [trim($rule[1]), $rule[2]], $rules), 1, 0);
    }
}
