<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as WhoIsWorkingStylesTest does, because ListProjectsControllerTest sees the tooltip but not how a long name is cut on a phone.
#[CoversNothing]
final class ProjectCardStylesTest extends TestCase
{
    public function testALongProjectNameEndsInAnEllipsisOnOneLine(): void
    {
        $link = $this->rule('.pblock .plink', $this->sheet());

        self::assertMatchesRegularExpression('/overflow:\s*hidden/', $link);
        self::assertMatchesRegularExpression('/text-overflow:\s*ellipsis/', $link);
        self::assertMatchesRegularExpression('/white-space:\s*nowrap/', $link);
        self::assertMatchesRegularExpression('/min-width:\s*0/', $link);
    }

    // text-overflow cuts only in a block box, so a flex link on a phone would lose the ellipsis and push the card wider.
    public function testOnAPhoneTheNameStaysABlockWithA44PxTapTarget(): void
    {
        $link = $this->rule('.pblock .plink', $this->phoneBlock());

        self::assertMatchesRegularExpression('/display:\s*block/', $link);
        self::assertMatchesRegularExpression('/line-height:\s*44px/', $link);
        self::assertDoesNotMatchRegularExpression('/white-space|overflow/', $link);
    }

    // The cost line prints a model without a price by its name, and such a name may have no point to break at.
    public function testALongUnpricedModelNameInTheCostLineWrapsInsideTheCard(): void
    {
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere/', $this->rule('.pblock .stat', $this->sheet()));
    }

    private function sheet(): string
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        return (string) preg_replace('~/\*.*?\*/~s', '', $sheet);
    }

    private function rule(string $selector, string $css): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $found = array_filter($rules, static fn (array $rule): bool => trim($rule[1]) === $selector);

        return [] === $found ? self::fail($selector) : implode(' ', array_column($found, 2));
    }

    private function phoneBlock(): string
    {
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $this->sheet(), $blocks);

        return implode("\n", $blocks[1]);
    }
}
