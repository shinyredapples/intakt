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

    public function testAnHtmlCommentOverSeveralLinesIsHidden(): void
    {
        self::assertSame("a \n\n b", MarkdownText::visible("a <!-- one\ntwo\nthree --> b"));
    }

    public function testAnUnclosedHtmlCommentHidesTheRest(): void
    {
        self::assertSame("a \n", MarkdownText::visible("a <!-- one\ntwo"));
    }

    public function testFencedLinesAreMarkedAndKeepTheirCommentMarkers(): void
    {
        self::assertSame(
            [['```', true], ['<!-- shown', true], ['```', true], ['after', false]],
            MarkdownText::visibleLines("```\n<!-- shown\n```\nafter"),
        );
    }

    public function testAFenceInsideAnHtmlCommentOpensNoBlock(): void
    {
        self::assertSame([['', false], ['', false], ['', false], ['after', false]], MarkdownText::visibleLines("<!--\n```\n-->\nafter"));
    }

    public function testAFenceClosesOnlyWithTheSameCharacterAtLeastAsLong(): void
    {
        self::assertSame(
            [true, true, true, true, true, false],
            array_map(static fn (array $line): bool => $line[1], MarkdownText::visibleLines("````\n```\n~~~~\n``` x\n`````\nafter")),
        );
    }
}
