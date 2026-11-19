<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenListItems::class)]
#[UsesClass(MarkdownText::class)]
final class OpenListItemsTest extends TestCase
{
    private const string NESTED = "- a\n  - b\nx";

    private const int AFTER_NESTED = 10;

    public function testALineBelongsToTheInnermostItemWhoseContentColumnItsIndentReaches(): void
    {
        self::assertSame(4, new OpenListItems(self::NESTED)->columnFor(self::AFTER_NESTED, 4));
        self::assertSame(2, new OpenListItems(self::NESTED)->columnFor(self::AFTER_NESTED, 3));
        self::assertNull(new OpenListItems(self::NESTED)->columnFor(self::AFTER_NESTED, 1));
    }

    public function testALessIndentedLineThatIsNoItemEndsTheItemsAboveIt(): void
    {
        self::assertNull(new OpenListItems("- a\ntext\n  y")->columnFor(9, 2));
    }

    public function testABlankLineEndsNoItem(): void
    {
        self::assertSame(2, new OpenListItems("- a\n\n  y")->columnFor(5, 2));
    }

    public function testAMarkerOnALinePassedOverStartsNoItem(): void
    {
        $text = "- a\n  ~~~\n  - b\n  ~~~\n    y";
        $items = new OpenListItems($text);
        $items->passOver(4, 21);

        self::assertSame(2, $items->columnFor(22, 4));
        self::assertSame(4, new OpenListItems($text)->columnFor(22, 4));
    }

    public function testTheFirstLineOfAPartPassedOverIsStillRead(): void
    {
        $items = new OpenListItems("- ~~~\n  - b\n  ~~~\n  y");
        $items->passOver(2, 17);

        self::assertSame(2, $items->columnFor(18, 2));
    }

    public function testAMarkerFourColumnsPastItsContainersContentStartsNoItem(): void
    {
        self::assertNull(new OpenListItems("    - a\n      y")->columnFor(8, 6));
    }

    public function testATabAfterTheMarkerPutsTheContentAtTheNextTabStop(): void
    {
        self::assertSame(4, new OpenListItems("1.\ta\n    y")->columnFor(5, 4));
    }

    public function testAThematicBreakIsNoItem(): void
    {
        self::assertNull(new OpenListItems("* * *\n  y")->columnFor(6, 2));
    }
}
