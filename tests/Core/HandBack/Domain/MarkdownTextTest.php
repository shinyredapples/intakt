<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\MarkdownText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownText::class)]
final class MarkdownTextTest extends TestCase
{
    public function testAnHtmlCommentOnOneLineIsHidden(): void
    {
        self::assertSame("a  b\nc", MarkdownText::visible("a <!-- hidden --> b\r\nc"));
    }

    public function testAnHtmlCommentOverSeveralLinesIsHiddenKeepingItsLineBreaks(): void
    {
        self::assertSame("a \n\n b", MarkdownText::visible("a <!-- one\ntwo\nthree --> b"));
    }

    public function testAnUnclosedHtmlCommentHidesTheRest(): void
    {
        self::assertSame("a \n", MarkdownText::visible("a <!-- one\ntwo"));
    }

    public function testAFencedBlockIsVisibleWithItsCommentMarkers(): void
    {
        self::assertSame("```\n<!-- shown\n```\nafter", MarkdownText::visible("```\n<!-- shown\n```\nafter"));
    }

    public function testAFencedBlockIsNoProse(): void
    {
        self::assertSame("before\n\n\n\nafter", MarkdownText::visibleProse("before\n```\n<!-- shown\n```\nafter"));
    }

    public function testAFenceInsideAnHtmlCommentOpensNoBlock(): void
    {
        self::assertSame("\n\n\nafter", MarkdownText::visibleProse("<!--\n```\n-->\nafter"));
    }

    public function testAFenceClosesOnlyWithTheSameCharacterAtLeastAsLong(): void
    {
        self::assertSame("\n\n\n\n\nafter", MarkdownText::visibleProse("````\n```\n~~~~\n``` x\n`````\nafter"));
    }

    public function testAnUnclosedFenceRunsToTheEnd(): void
    {
        self::assertSame("before\n\n", MarkdownText::visibleProse("before\n~~~\n## Evidence"));
    }
}
