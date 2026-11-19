<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeSpans::class)]
#[UsesClass(ParagraphEnd::class)]
#[UsesClass(MarkdownText::class)]
final class CodeSpansTest extends TestCase
{
    public function testASpanRunsToTheNextRunOfTheSameLength(): void
    {
        self::assertSame('`b`', new CodeSpans('a `b` c')->spanOrLiteralAt(2, 0));
        self::assertSame('`` b ` c ``', new CodeSpans('a `` b ` c `` d')->spanOrLiteralAt(2, 0));
    }

    public function testARunWithoutAPartnerOfItsLengthIsLiteral(): void
    {
        self::assertSame('`', new CodeSpans('a ` b `` c')->spanOrLiteralAt(2, 0));
    }

    public function testASpanDoesNotLeaveItsParagraph(): void
    {
        self::assertSame('`', new CodeSpans("a ` b\n\n` c")->spanOrLiteralAt(2, 0));
        self::assertSame('`', new CodeSpans("## a ` b\n` c")->spanOrLiteralAt(5, 0));
        self::assertSame('`', new CodeSpans("a ` b\n- ` c")->spanOrLiteralAt(2, 0));
    }

    public function testABacktickAfterAnOddNumberOfBackslashesOpensNoSpan(): void
    {
        self::assertSame('`', new CodeSpans('a \` b `')->spanOrLiteralAt(3, 0));
        self::assertSame('``b`', new CodeSpans('a \``b`')->spanOrLiteralAt(3, 0));
        self::assertSame('`b`', new CodeSpans('a \\\\`b`')->spanOrLiteralAt(4, 0));
    }

    public function testABackslashInsideTheSpanReadBeforeEscapesNothing(): void
    {
        self::assertSame('`c`', new CodeSpans('`a\``c`')->spanOrLiteralAt(4, 4));
    }

    public function testAPairIsStillFoundAfterAnUnpairedRunScannedTheParagraph(): void
    {
        $spans = new CodeSpans('` `` ``` `` x ``');

        self::assertSame('`', $spans->spanOrLiteralAt(0, 0));
        self::assertSame('`` ``` ``', $spans->spanOrLiteralAt(2, 1));
        self::assertSame('``', $spans->spanOrLiteralAt(14, 11));
    }

    public function testWhatAnEarlierParagraphHeldIsForgottenInTheNext(): void
    {
        $spans = new CodeSpans("` a\n\n`b` `` c\n\n``d``");

        self::assertSame('`', $spans->spanOrLiteralAt(0, 0));
        self::assertSame('`b`', $spans->spanOrLiteralAt(5, 1));
        self::assertSame('``', $spans->spanOrLiteralAt(9, 8));
        self::assertSame('``d``', $spans->spanOrLiteralAt(15, 11));
    }
}
