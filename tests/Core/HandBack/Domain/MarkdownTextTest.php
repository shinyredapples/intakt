<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
final class MarkdownTextTest extends TestCase
{
    public function testAnHtmlCommentOnOneLineIsHidden(): void
    {
        self::assertSame("a  b\nc", MarkdownText::visible("a <!-- hidden --> b\r\nc"));
    }

    public function testTheEmptyHtmlCommentsAreWholeComments(): void
    {
        self::assertSame('a  E1 b  E2', MarkdownText::visible('a <!--> E1 b <!---> E2'));
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

    public function testAnUnclosedFenceInsideAListItemEndsWithTheItem(): void
    {
        self::assertSame("- one\n\n\n\n- two  y", MarkdownText::visibleProse("- one\n\n  ```\n  code\n- two <!-- x --> y"));
        self::assertSame("1. one\n   text\n\n\n\n\n  after", MarkdownText::visibleProse("1. one\n   text\n\n   ```\n\n   code\n  after"));
    }

    public function testAClosedFenceInsideAListItemKeepsItsLessIndentedLinesOutside(): void
    {
        self::assertSame("- one\n\n- two\n\n", MarkdownText::visibleProse("- one\n  ```\n- two\n```\nrest"));
    }

    public function testAFenceInsideAnIndentedListItemEndsBelowTheContentOfThatItem(): void
    {
        self::assertSame("- one\n - two\n\n\n  three", MarkdownText::visibleProse("- one\n - two\n   ```\n   code\n  three"));
        self::assertSame("- one\n  text\n\n\n\n  more\n\n", MarkdownText::visibleProse("- one\n  text\n   ```\n  code\n   ```\n  more\n```\nrest"));
    }

    public function testAnIndentedFenceOutsideAListKeepsItsLessIndentedLines(): void
    {
        self::assertSame("text\n\n\n\n\nafter", MarkdownText::visibleProse("text\n\n  ```\ncode\n  ```\nafter"));
        self::assertSame("1. one\n\n\n\nafter", MarkdownText::visibleProse("1. one\n  ```\ncode\n  ```\nafter"));
        self::assertSame("- one\n\ntext\n\n\n\nafter", MarkdownText::visibleProse("- one\n\ntext\n  ```\ncode\n  ```\nafter"));
    }

    public function testACommentMarkerInsideACodeSpanIsVisible(): void
    {
        self::assertSame('Output: `<!-- E1 -->` done', MarkdownText::visible('Output: `<!-- E1 -->` done'));
        self::assertSame('a ``<!-- ` -->`` b', MarkdownText::visibleProse('a ``<!-- ` -->`` b'));
        self::assertSame('`<!--` and E1', MarkdownText::visible('`<!--` and E1'));
    }

    public function testABacktickWithoutItsPairOpensNoCodeSpan(): void
    {
        self::assertSame('a ` b  c', MarkdownText::visible('a ` b <!-- x --> c'));
        self::assertSame("a ` b\n\n ` c", MarkdownText::visible("a ` b\n\n<!-- x --> ` c"));
    }

    public function testABackslashEscapedBacktickOpensNoCodeSpan(): void
    {
        self::assertSame('a \`  `', MarkdownText::visible('a \` <!-- x --> `'));
        self::assertSame('a \``<!-- x -->`', MarkdownText::visible('a \``<!-- x -->`'));
        self::assertSame('a \\\\`<!-- x -->`', MarkdownText::visible('a \\\\`<!-- x -->`'));
    }

    public function testACodeSpanEndsAtAHeadingOrFenceLine(): void
    {
        self::assertSame("a `\n# b\n\n`", MarkdownText::visible("a `\n# b\n<!-- x -->\n`"));
        self::assertSame("a `\n~~~\n<!-- x -->\n`", MarkdownText::visible("a `\n~~~\n<!-- x -->\n`"));
    }

    public function testAFenceOpenedRightAfterAListMarkerBelongsToThatItem(): void
    {
        self::assertSame("- ```\n  <!-- x -->\nafter ", MarkdownText::visible("- ```\n  <!-- x -->\nafter <!-- y -->"));
        self::assertSame("- ```\n\n\n- two", MarkdownText::visibleProse("- ```\n  code\n  ```\n- two"));
    }

    public function testAFenceOpenedAfterATabbedMarkerBelongsToThatItem(): void
    {
        self::assertSame("-\t```\n\n\n- two", MarkdownText::visibleProse("-\t```\n\tcode\n\t```\n- two"));
        self::assertSame("1.\t~~~\n\t<!-- x -->\n\n\t~~~\nafter ", MarkdownText::visible("1.\t~~~\n\t<!-- x -->\n\n\t~~~\nafter <!-- y -->"));
    }

    public function testALineStartsAfterTheLineBreakBeforeIt(): void
    {
        self::assertSame([0, 0, 2, 2, 4], array_map(static fn (int $position): int => MarkdownText::lineStartAt("a\nb\n", $position), [0, 1, 2, 3, 4]));
    }

    public function testALineEndsAtItsLineBreakOrTheEndOfTheText(): void
    {
        self::assertSame([1, 1, 3, 3], array_map(static fn (int $position): int => MarkdownText::lineEndAt("a\nb", $position), [0, 1, 2, 3]));
    }

    public function testLongPartsAreReadWithoutExhaustingTheRegexEngine(): void
    {
        $long = str_repeat('x', 256 * 1024);

        self::assertSame(strlen('`'.$long.'`'), strlen(MarkdownText::visible('`'.$long.'`')));
        self::assertSame("a\n\n", MarkdownText::visibleProse("a\n```\n".$long));
        self::assertSame('a ', MarkdownText::visible('a <!--'.$long));
    }

    public function testABacktickInsideAnHtmlCommentOpensNoCodeSpan(): void
    {
        self::assertSame('a  ` E2', MarkdownText::visible('a <!-- ` E1 --> ` E2'));
    }

    public function testAListItemLineHasTheColumnOfItsContent(): void
    {
        self::assertSame([2, 3, 5, 2, 4], array_map(MarkdownText::listItemColumnOf(...), ['- a', '1. a', '  -  a', '-      code', MarkdownText::withSpacesForTabs("-\ta")]));
        self::assertSame([null, null, null], array_map(MarkdownText::listItemColumnOf(...), ['* * *', 'text', '-a']));
    }

    public function testTabsInTheIndentRunToTheNextTabStop(): void
    {
        self::assertSame(['    a', '    a', "1.  a\tb"], array_map(MarkdownText::withSpacesForTabs(...), ["\ta", "  \ta", "1.\ta\tb"]));
    }

    // Each line is read once for the list items, so thousands of fences with a marker shown in each still leave the last one in the two-digit item.
    public function testAFenceAfterThousandsOfFencesInAnItemIsStillInThatItem(): void
    {
        $last = "      ~~~\n    <!-- E1 -->\n    ~~~";

        self::assertStringEndsWith($last, MarkdownText::visible("10. Example\n\n".str_repeat("    ~~~\n    - shown\n    ~~~\n\n", 5_000).$last));
    }

    public function testATabIndentedFenceAtTheContentColumnOfATwoDigitItemShowsItsComment(): void
    {
        self::assertSame("10. a\n\n\t```\n\t<!-- x -->\n\t```\n", MarkdownText::visible("10. a\n\n\t```\n\t<!-- x -->\n\t```\n<!-- y -->"));
    }

    public function testATabIndentedFenceOutsideAnyItemOpensNone(): void
    {
        self::assertSame("\t~~~\n\nafter", MarkdownText::visible("\t~~~\n<!-- x -->\nafter"));
    }
}
