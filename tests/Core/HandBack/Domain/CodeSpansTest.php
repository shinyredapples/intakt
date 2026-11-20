<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeSpans::class)]
#[UsesClass(ParagraphEnd::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
final class CodeSpansTest extends TestCase
{
    public function testASpanRunsToTheNextRunOfTheSameLength(): void
    {
        self::assertSame('`b`', $this->partAt('a `b` c', 2));
        self::assertSame('`` b ` c ``', $this->partAt('a `` b ` c `` d', 2));
    }

    public function testARunWithoutAPartnerOfItsLengthIsLiteral(): void
    {
        self::assertSame('`', $this->partAt('a ` b `` c', 2));
    }

    public function testASpanDoesNotLeaveItsParagraph(): void
    {
        self::assertSame('`', $this->partAt("a ` b\n\n` c", 2));
        self::assertSame('`', $this->partAt("## a ` b\n` c", 5));
        self::assertSame('`', $this->partAt("a ` b\n- ` c", 2));
    }

    public function testASpanMayRunOverTheLinesOfItsParagraph(): void
    {
        self::assertSame("` b\nc `", $this->partAt("a ` b\nc ` d", 2));
    }

    public function testABacktickAfterAnOddNumberOfBackslashesOpensNoSpan(): void
    {
        self::assertSame('`', $this->partAt('a \` b `', 3));
        self::assertSame('``b`', $this->partAt('a \``b`', 3));
        self::assertSame('`b`', $this->partAt('a \\\\`b`', 4));
    }

    public function testABackslashInsideTheSpanReadBeforeEscapesNothing(): void
    {
        self::assertSame('`c`', CodeSpans::of('`a\``c`')->inTheParagraphAt(0)->spanOrLiteralAt(4, 4));
    }

    public function testAPairIsFoundAmongRunsOfOtherLengthsInOneParagraph(): void
    {
        $spans = CodeSpans::of('` `` ``` `` x ``')->inTheParagraphAt(0);

        self::assertSame('`', $spans->spanOrLiteralAt(0, 0));
        self::assertSame('`` ``` ``', $spans->spanOrLiteralAt(2, 1));
        self::assertSame('``', $spans->spanOrLiteralAt(14, 11));
    }

    public function testTheRunsOfAParagraphAreListedAgainOnlyBehindItsEnd(): void
    {
        $text = "` a\n\n`b` `` c\n\n``d``";
        $first = CodeSpans::of($text)->inTheParagraphAt(0);
        $second = $first->inTheParagraphAt(5);

        self::assertSame($first, $first->inTheParagraphAt(2));
        self::assertSame($second, $second->inTheParagraphAt(9));
        self::assertSame(['`', '`b`', '``'], [$first->spanOrLiteralAt(0, 0), $second->spanOrLiteralAt(5, 1), $second->spanOrLiteralAt(9, 8)]);
        self::assertSame('``d``', $second->inTheParagraphAt(15)->spanOrLiteralAt(15, 11));
    }

    public function testEachOfManyPairsInOneParagraphFindsItsOwnPartner(): void
    {
        $spans = CodeSpans::of(str_repeat('`a` ', 1000))->inTheParagraphAt(0);

        self::assertSame(array_fill(0, 1000, '`a`'), array_map(static fn (int $pair): string => $spans->spanOrLiteralAt(4 * $pair, 4 * $pair), range(0, 999)));
    }

    private function partAt(string $text, int $start): string
    {
        return CodeSpans::of($text)->inTheParagraphAt($start)->spanOrLiteralAt($start, 0);
    }
}
