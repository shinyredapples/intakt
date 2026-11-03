<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\MarkdownBlock;
use Intakt\Gates\MarkdownBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownBlocks::class)]
#[UsesClass(MarkdownBlock::class)]
final class MarkdownBlocksTest extends TestCase
{
    public function testLinesOutsideAFenceAreText(): void
    {
        $blocks = new MarkdownBlocks()->of(['| Status |', 'text']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Text], $blocks);
    }

    public function testABlockRunsFromItsOpeningToItsClosingFence(): void
    {
        $blocks = new MarkdownBlocks()->of(['before', '```', '| started |', '```', 'after']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Text], $blocks);
    }

    public function testAClosingFenceEndingInACarriageReturnClosesTheBlock(): void
    {
        $blocks = new MarkdownBlocks()->of(["```\r", "| started |\r", "```\r", "| open |\r"]);

        self::assertSame([MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Text], $blocks);
    }

    public function testABacktickFenceDoesNotCloseATildeBlock(): void
    {
        $blocks = new MarkdownBlocks()->of(['~~~', '```', '| started |', '~~~', '| open |']);

        self::assertSame([MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Text], $blocks);
    }

    public function testATildeFenceDoesNotCloseABacktickBlock(): void
    {
        $blocks = new MarkdownBlocks()->of(['```', '~~~', '| started |', '```', '| open |']);

        self::assertSame([MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Text], $blocks);
    }

    public function testAFenceIndentedByATabOpensNoBlock(): void
    {
        $blocks = new MarkdownBlocks()->of(["\t```", '| open |']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Text], $blocks);
    }

    public function testAFenceIndentedByATabDoesNotCloseABlock(): void
    {
        $blocks = new MarkdownBlocks()->of(['```', "\t```", '| started |']);

        self::assertSame([MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence], $blocks);
    }

    public function testAnUnclosedBlockRunsToTheEndOfTheFile(): void
    {
        $blocks = new MarkdownBlocks()->of(['| open |', '```', '| started |', 'text']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence], $blocks);
    }

    public function testACommentRunsFromItsOpenerToTheLineHoldingItsCloser(): void
    {
        $blocks = new MarkdownBlocks()->of(['before', '<!--', '| row |', 'end -->', 'after']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Comment, MarkdownBlock::Comment, MarkdownBlock::Comment, MarkdownBlock::Text], $blocks);
    }

    public function testAOneLineCommentClosesOnItsOwnLine(): void
    {
        $blocks = new MarkdownBlocks()->of(['<!-- note -->', '| open |']);

        self::assertSame([MarkdownBlock::Comment, MarkdownBlock::Text], $blocks);
    }

    public function testACommentOpenerIndentedFourSpacesOpensNoComment(): void
    {
        $blocks = new MarkdownBlocks()->of(['    <!--', '| open |']);

        self::assertSame([MarkdownBlock::Text, MarkdownBlock::Text], $blocks);
    }

    public function testAFenceInsideACommentOpensNoBlock(): void
    {
        $blocks = new MarkdownBlocks()->of(['<!--', '```', '-->', '| open |']);

        self::assertSame([MarkdownBlock::Comment, MarkdownBlock::Comment, MarkdownBlock::Comment, MarkdownBlock::Text], $blocks);
    }

    public function testACommentOpenerInsideAFenceOpensNoComment(): void
    {
        $blocks = new MarkdownBlocks()->of(['```', '<!--', '```', '| open |', '-->']);

        self::assertSame([MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Fence, MarkdownBlock::Text, MarkdownBlock::Text], $blocks);
    }
}
