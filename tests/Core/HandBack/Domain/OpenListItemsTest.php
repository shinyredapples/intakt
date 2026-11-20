<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenListItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
final class OpenListItemsTest extends TestCase
{
    private const string NESTED = "- a\n  - b\nx";

    private const int AFTER_NESTED = 10;

    public function testALineBelongsToTheInnermostItemWhoseContentColumnItsIndentReaches(): void
    {
        $items = OpenListItems::of(self::NESTED)->readUpTo(self::AFTER_NESTED);

        self::assertSame([4, 2, null], [$items->columnFor(4), $items->columnFor(3), $items->columnFor(1)]);
    }

    public function testALessIndentedLineThatIsNoItemEndsTheItemsAboveIt(): void
    {
        self::assertNull(OpenListItems::of("- a\ntext\n  y")->readUpTo(9)->columnFor(2));
    }

    public function testABlankLineEndsNoItem(): void
    {
        self::assertSame(2, OpenListItems::of("- a\n\n  y")->readUpTo(5)->columnFor(2));
    }

    public function testAMarkerOnALinePassedOverStartsNoItem(): void
    {
        $text = "- a\n  ~~~\n  - b\n    ~~~\n    y";

        self::assertSame(2, OpenListItems::of($text)->passOver(4, 23)->readUpTo(24)->columnFor(4));
        self::assertSame(4, OpenListItems::of($text)->readUpTo(24)->columnFor(4));
    }

    public function testTheFirstLineOfAPartPassedOverIsStillRead(): void
    {
        self::assertSame(2, OpenListItems::of("- ~~~\n  - b\n  ~~~\n  y")->passOver(2, 17)->readUpTo(18)->columnFor(2));
    }

    public function testReadingUpToALineAlreadyReadChangesNothing(): void
    {
        $items = OpenListItems::of("- a\nb\n  c")->readUpTo(6);

        self::assertSame($items->columnFor(2), $items->readUpTo(4)->columnFor(2));
    }

    public function testAMarkerFourColumnsPastItsContainersContentStartsNoItem(): void
    {
        self::assertNull(OpenListItems::of("    - a\n      y")->readUpTo(8)->columnFor(6));
    }

    public function testATabAfterTheMarkerPutsTheContentAtTheNextTabStop(): void
    {
        self::assertSame(4, OpenListItems::of("1.\ta\n    y")->readUpTo(5)->columnFor(4));
    }

    public function testAThematicBreakIsNoItem(): void
    {
        self::assertNull(OpenListItems::of("* * *\n  y")->readUpTo(6)->columnFor(2));
    }

    public function testAnOrderedMarkerNotNumberedOneStartsNoItemRightAfterAParagraphLine(): void
    {
        self::assertFalse(OpenListItems::of("A paragraph\n2. ~~~")->readUpTo(12)->letsAnItemStartOn('2. ~~~'));
    }

    public function testAMarkerAfterACommentFillingItsLineStartsAnItem(): void
    {
        self::assertSame(4, OpenListItems::of("<!-- note -->\n10. Example\n    y")->passOver(0, 13)->readUpTo(26)->columnFor(4));
    }
}
