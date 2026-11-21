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

    public function testTheOutcomeChipLeadingARowOrACardIsSetInTheTitlesFifteenHeadlineType(): void
    {
        self::assertMatchesRegularExpression('/font:\s*600 var\(--t15\)\/1 var\(--f-head\)/', $this->rule('.review-outcome .chip, .card .outcome .chip'));
    }

    public function testTheOutcomeChipOfANarrowBoardCardWrapsInsideTheCardInsteadOfOverflowingIt(): void
    {
        $rule = $this->rule('.card .outcome .chip');

        self::assertMatchesRegularExpression('/white-space:\s*normal/', $rule);
        self::assertMatchesRegularExpression('/max-width:\s*100%/', $rule);
        self::assertMatchesRegularExpression('/height:\s*auto/', $rule);
    }

    private function rule(string $selector): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $sheet), $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector || in_array($selector, array_map(trim(...), explode(',', $rule[1])), true));

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }
}
