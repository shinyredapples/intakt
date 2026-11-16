<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as DisclosureStylesTest does, because no page is needed to see a rule.
#[CoversNothing]
final class ReviewListStylesTest extends TestCase
{
    public function testEveryRowLinkIsAtLeastFortyFourPixelsTallAtEveryWidth(): void
    {
        self::assertMatchesRegularExpression('/min-height:\s*44px/', $this->rule('.review-link'));
    }

    public function testTheRowLinkIsABlockSoTheWholeRowTakesTheClick(): void
    {
        self::assertMatchesRegularExpression('/display:\s*flex/', $this->rule('.review-link'));
    }

    public function testTheRoundIdIsSelectedWholeWithOneClick(): void
    {
        self::assertMatchesRegularExpression('/user-select:\s*all/', $this->rule('.round-id code'));
    }

    private function rule(string $selector): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }
}
