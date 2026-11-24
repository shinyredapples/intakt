<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\ReadableLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadableLines::class)]
final class ReadableLinesTest extends TestCase
{
    public function testLinesInsideAFenceAreEmptyAndTheOthersKept(): void
    {
        self::assertSame(['## A', '', '', '', 'text'], ReadableLines::of("## A\n```\n## B\n```\ntext")->lines);
    }

    public function testABodyThatClosesEveryBlockNeedsNoCloser(): void
    {
        self::assertNull(ReadableLines::of("```\ncode\n```\n<!-- note -->\ntext")->closer);
    }

    public function testAnUnclosedFenceIsClosedWithItsOwnMarker(): void
    {
        self::assertSame('~~~~', ReadableLines::of("text\n~~~~\n~~~\ncode")->closer);
    }

    public function testAnUnclosedFenceInAListItemIsClosedAtTheItemsContentColumn(): void
    {
        self::assertSame('   ```', ReadableLines::of("1. step\n   ```\n   code")->closer);
    }

    public function testAnUnclosedCommentIsClosedWithACommentEnd(): void
    {
        self::assertSame('-->', ReadableLines::of("text <!-- a note\nmore")->closer);
    }

    public function testUnclosedOutsideTextIsClosedWithItsEndMarker(): void
    {
        self::assertSame('<!-- /external -->', ReadableLines::of("<!-- external -->\nquoted")->closer);
    }

    public function testAFenceInsideACommentIsTheCommentsText(): void
    {
        self::assertSame('-->', ReadableLines::of("<!--\n```")->closer);
    }

    public function testWindowsAndOldMacLineEndingsBecomeLineFeeds(): void
    {
        self::assertSame("a\nb\nc", ReadableLines::withLineFeeds("a\r\nb\rc"));
    }
}
