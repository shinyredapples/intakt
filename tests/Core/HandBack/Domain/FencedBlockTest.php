<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FencedBlock::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(OpenListItems::class)]
final class FencedBlockTest extends TestCase
{
    private const int ONE_MORE_THAN_THE_LARGEST_REGEX_QUANTIFIER = 65_536;

    public function testAFenceClosesAtARunOfItsCharacterAtLeastAsLong(): void
    {
        self::assertSame("```\na\n````", FencedBlock::at("```\na\n````\nb", 0));
        self::assertSame("~~~\na\n~~~ \t", FencedBlock::at("~~~\na\n~~~ \t\nb", 0));
    }

    public function testAShorterRunOrAnotherCharacterClosesNothing(): void
    {
        self::assertSame("````\n```\n~~~~\nb", FencedBlock::at("````\n```\n~~~~\nb", 0));
    }

    public function testARunWithTextAfterItClosesNothing(): void
    {
        self::assertSame("```\n``` x\n```", FencedBlock::at("```\n``` x\n```\nb", 0));
    }

    #[TestWith(['`'])]
    #[TestWith(['~'])]
    public function testAFenceRunLongerThanTheLargestRegexQuantifierIsClosedByARunAsLong(string $character): void
    {
        $run = str_repeat($character, self::ONE_MORE_THAN_THE_LARGEST_REGEX_QUANTIFIER);

        self::assertSame($run."\ncode\n".$run, FencedBlock::at($run."\ncode\n".$run."\nafter", 0));
    }

    #[TestWith(['`'])]
    #[TestWith(['~'])]
    public function testAFenceRunLongerThanTheLargestRegexQuantifierIsNotClosedByAShorterRun(string $character): void
    {
        $run = str_repeat($character, self::ONE_MORE_THAN_THE_LARGEST_REGEX_QUANTIFIER);
        $text = $run."\ncode\n".substr($run, 1)."\nafter";

        self::assertSame($text, FencedBlock::at($text, 0));
    }

    public function testOutsideAListAClosingRunIsIndentedAtMostThreeSpaces(): void
    {
        self::assertSame("```\n   ```", FencedBlock::at("```\n   ```\nb", 0));
        self::assertSame("```\n    ```\nb", FencedBlock::at("```\n    ```\nb", 0));
        self::assertSame("```\n\t```\nb", FencedBlock::at("```\n\t```\nb", 0));
    }

    public function testInsideAListItemAClosingRunIsIndentedFromTheContentColumnOfTheItem(): void
    {
        self::assertSame("```\n    Example\n    ```", FencedBlock::at("10. ```\n    Example\n    ```\n    after", 4));
        self::assertSame("    ```\n    code\n       ```", FencedBlock::at("10. one\n    ```\n    code\n       ```\n    after", 8));
        self::assertSame("```\n        ```\n    after", FencedBlock::at("10. ```\n        ```\n    after", 4));
    }

    public function testATabIndentedFenceInATwoDigitItemClosesAtATabIndentedRun(): void
    {
        self::assertSame("\t```\n\tx\n\t```", FencedBlock::at("10. a\n\n\t```\n\tx\n\t```\nafter", 7));
    }

    public function testAFenceOpenedAfterATabbedMarkerHasTheContentColumnOfTheTabStop(): void
    {
        self::assertSame("```\n\tx\n\t```", FencedBlock::at("-\t```\n\tx\n\t```\n\tafter", 2));
        self::assertSame("```\n    x\n    ```", FencedBlock::at("-\t```\n    x\n    ```\n    after", 2));
    }

    // The reference CommonMark parser reads it this way: the tab puts the content at column four, so a line indented two is outside the item and ends its fence.
    public function testALineLeftOfTheContentColumnOfATabbedMarkerEndsItsFence(): void
    {
        self::assertSame('```', FencedBlock::at("-\t```\n  x\n  ```\n- two", 2));
    }

    public function testAnUnclosedFenceInsideAListItemEndsWhereTheItemEnds(): void
    {
        self::assertSame("  ```\n  code\n", FencedBlock::at("- one\n\n  ```\n  code\n\n- two", 7));
        self::assertSame("  ```\n  code\n\n- two", FencedBlock::at("text\n\n  ```\n  code\n\n- two", 6));
    }

    public function testAFenceBelowALessIndentedLineThatIsNoItemIsInNoItem(): void
    {
        self::assertSame("  ```\ncode\n  ```", FencedBlock::at("- one\n\ntext\n  ```\ncode\n  ```\nafter", 12));
    }

    #[TestWith(['   ```', 0], 'three spaces outside any item')]
    #[TestWith(["10. a\n\n    ```", 7], 'at the content column of a two-digit item')]
    #[TestWith(["10. a\n\n       ```", 7], 'three spaces past the content column of a two-digit item')]
    #[TestWith(["10. a\n\n\t```", 7], 'a tab at the content column of a two-digit item')]
    public function testAFenceIndentedUpToThreeColumnsPastItsContainersContentOpens(string $text, int $start): void
    {
        self::assertTrue(FencedBlock::opensAt($text, $start));
    }

    #[TestWith(['    ```', 0], 'four spaces outside any item')]
    #[TestWith(["- a\n\n      ```", 5], 'four spaces past the content column of an item')]
    #[TestWith(["a\n\n    ```", 3], 'four spaces below a paragraph')]
    #[TestWith(["\t```", 0], 'a tab outside any item')]
    public function testAFenceIndentedFourColumnsPastItsContainersContentOpensNone(string $text, int $start): void
    {
        self::assertFalse(FencedBlock::opensAt($text, $start));
    }
}
