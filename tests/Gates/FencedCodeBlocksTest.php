<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FencedCodeBlocks::class)]
final class FencedCodeBlocksTest extends TestCase
{
    public function testLinesOutsideAFenceAreKept(): void
    {
        $lines = ['| Status |', 'text'];

        self::assertSame($lines, new FencedCodeBlocks()->blanked($lines));
    }

    public function testABlockIsBlankedFromItsOpeningToItsClosingFenceWithLineNumbersKept(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(['before', '```', '| started |', '```', 'after']);

        self::assertSame(['before', '', '', '', 'after'], $blanked);
    }

    public function testAClosingFenceEndingInACarriageReturnClosesTheBlock(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(["```\r", "| started |\r", "```\r", "| open |\r"]);

        self::assertSame(['', '', '', "| open |\r"], $blanked);
    }

    public function testABacktickFenceDoesNotCloseATildeBlock(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(['~~~', '```', '| started |', '~~~', '| open |']);

        self::assertSame(['', '', '', '', '| open |'], $blanked);
    }

    public function testATildeFenceDoesNotCloseABacktickBlock(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(['```', '~~~', '| started |', '```', '| open |']);

        self::assertSame(['', '', '', '', '| open |'], $blanked);
    }

    public function testAFenceIndentedByATabOpensNoBlock(): void
    {
        $lines = ["\t```", '| open |'];

        self::assertSame($lines, new FencedCodeBlocks()->blanked($lines));
    }

    public function testAFenceIndentedByATabDoesNotCloseABlock(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(['```', "\t```", '| started |']);

        self::assertSame(['', '', ''], $blanked);
    }

    public function testAnUnclosedBlockRunsToTheEndOfTheFile(): void
    {
        $blanked = new FencedCodeBlocks()->blanked(['| open |', '```', '| started |', 'text']);

        self::assertSame(['| open |', '', '', ''], $blanked);
    }
}
