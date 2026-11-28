<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Review\Contract\FindingKind;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundFinding::class)]
#[CoversClass(FindingUrgency::class)]
#[UsesClass(FindingKind::class)]
#[UsesClass(NearLine::class)]
#[UsesClass(QuoteNearLines::class)]
final class RoundFindingTest extends TestCase
{
    public function testAFindingsHeadlineIsItsFirstSentence(): void
    {
        self::assertSame('The total in src/A.php is off by 1.5.', new RoundFinding('F1', 'P1', 'The total in src/A.php is off by 1.5. It drops the last item.')->headline());
    }

    public function testTheQuotedCodeIsEveryCodeSpanAsTheReviewerWroteIt(): void
    {
        self::assertSame(['0', 'a`b', "x\ny"], new RoundFinding('F1', 'P2', "It returns `0`, quotes ``a`b`` and `x\ny`, and leaves `open.")->quotedCode());
    }

    public function testACodeSpanOfAMillionBytesIsStillQuoted(): void
    {
        $code = str_repeat('x', 1_000_000);

        // Compared by hash, because a failed comparison of the whole span prints a million bytes.
        self::assertSame([md5($code)], array_map(md5(...), new RoundFinding('F1', 'P2', 'The reviewer quoted `'.$code.'`.')->quotedCode()));
    }

    public function testAHeadlineNeverEndsInsideACodeSpanOfAMillionBytes(): void
    {
        $headline = 'It quotes `a. '.str_repeat('x', 1_000_000).'`.';

        self::assertSame(md5($headline), md5((string) new RoundFinding('F1', 'P2', $headline.' Then more.')->headline()));
    }

    public function testAFindingWithoutTextQuotesNoCode(): void
    {
        self::assertSame([], new RoundFinding('F1', 'P2', null)->quotedCode());
    }

    public function testAFindingsHeadlineNeverEndsInsideAnInlineCodeSpan(): void
    {
        self::assertSame('Keep `Ready? yes` as the prompt.', new RoundFinding('F1', 'P2', 'Keep `Ready? yes` as the prompt. The answer is required.')->headline());
    }

    public function testAHeadlineIgnoresAShortSecondSentenceAfterACodeSpan(): void
    {
        self::assertSame('Keep `Ready? yes` as the prompt.', new RoundFinding('F1', 'P2', 'Keep `Ready? yes` as the prompt. More.')->headline());
    }

    public function testAClosedCodeSpanStaysWholeWhenNoSentenceMarkFollows(): void
    {
        self::assertSame('Keep `Ready? yes` as the prompt', new RoundFinding('F1', 'P2', 'Keep `Ready? yes` as the prompt')->headline());
    }

    public function testAClosedCodeSpanStaysWholeWhenASecondLineFollows(): void
    {
        self::assertSame('Keep `Ready? yes` as the prompt', new RoundFinding('F1', 'P2', "Keep `Ready? yes` as the prompt\nMore")->headline());
    }

    public function testASpanOpenedByTwoBackticksStaysWhole(): void
    {
        self::assertSame('Keep ``Ready? yes`` as the prompt.', new RoundFinding('F1', 'P2', 'Keep ``Ready? yes`` as the prompt. More.')->headline());
    }

    public function testASingleBacktickInsideADoubleBacktickSpanDoesNotCloseIt(): void
    {
        self::assertSame('Keep ``a ` b? c`` here.', new RoundFinding('F1', 'P2', 'Keep ``a ` b? c`` here. More.')->headline());
    }

    public function testAnUnclosedBacktickIsReadAsAPlainCharacter(): void
    {
        self::assertSame('Keep `Ready?', new RoundFinding('F1', 'P2', 'Keep `Ready? yes')->headline());
    }

    public function testATextOfOnlyUnicodeWhiteSpaceHasNoHeadline(): void
    {
        self::assertNull(new RoundFinding('F1', 'P2', "\u{00A0}\u{2003}\n")->headline());
    }

    public function testATextThatIsNotValidUtf8HasNoHeadline(): void
    {
        self::assertNull(new RoundFinding('F1', 'P2', "\xff\xfe")->headline());
    }

    public function testAFindingsHeadlineEndsAtItsFirstLine(): void
    {
        self::assertSame('No test covers Cart::total', new RoundFinding('F1', 'P2', "No test covers Cart::total\nAdd one for an empty cart.")->headline());
    }

    public function testAFindingWithoutTextOrWithOnlyWhiteSpaceHasNoHeadline(): void
    {
        self::assertSame([null, null], [new RoundFinding('F1', null, null)->headline(), new RoundFinding('F1', 'P2', " \n\t ")->headline()]);
    }

    public function testACodeSpanThatCrossesALineStaysWhole(): void
    {
        self::assertSame("Keep `Ready? yes\ncontinue` as the prompt.", new RoundFinding('F1', 'P2', "Keep `Ready? yes\ncontinue` as the prompt. More.")->headline());
    }

    public function testAHeadlineCutAtALineBreakDropsTheWhiteSpaceBeforeIt(): void
    {
        self::assertSame('Keep `a` as is', new RoundFinding('F1', 'P2', "Keep `a` as is \u{00A0}\nnext")->headline());
    }

    public function testAVeryLongFirstSentenceStillEndsAtItsMark(): void
    {
        $sentence = str_repeat('word `a` and ', 2000).'end.';

        self::assertSame($sentence, new RoundFinding('F1', 'P2', $sentence.' More.')->headline());
    }

    public function testAFindingWithASecondSentenceGoesOnAfterItsHeadline(): void
    {
        self::assertTrue(new RoundFinding('F1', 'P2', 'Keep `Ready? yes` as the prompt. More.')->goesOnAfterItsShortLine());
    }

    public function testAFindingOfOneSentenceOrWithoutTextDoesNotGoOnAfterItsHeadline(): void
    {
        self::assertSame([false, false, false], [
            new RoundFinding('F1', 'P2', " Keep `Ready? yes` as the prompt.\u{2003}")->goesOnAfterItsShortLine(),
            new RoundFinding('F1', 'P2', "\u{00A0}")->goesOnAfterItsShortLine(),
            new RoundFinding('F1', 'P2', null)->goesOnAfterItsShortLine(),
        ]);
    }

    public function testAFindingWithAStoredTitleShowsTheTitleAsItsShortLine(): void
    {
        self::assertSame('Discount lost', new RoundFinding('F1', 'P1', 'A cart of 100 loses its discount. More.', title: 'Discount lost')->shortLine());
    }

    public function testAFindingRecordedWithoutATitleFallsBackToTheHeadlineOfItsText(): void
    {
        self::assertSame('A cart loses its discount.', new RoundFinding('F1', 'P1', 'A cart loses its discount. More.')->shortLine());
    }

    public function testAFindingWithABlankTitleFallsBackToTheHeadline(): void
    {
        self::assertSame('Broken.', new RoundFinding('F1', 'P1', 'Broken. More.', title: " \u{00A0}")->shortLine());
    }

    public function testAFindingRecordedWithoutAKindShowsNoSymbol(): void
    {
        self::assertNull(new RoundFinding('F1', 'P1', 'Broken.')->kindWords());
    }

    public function testAFindingWithAStoredKindNamesItInWords(): void
    {
        self::assertSame('No test', new RoundFinding('F1', 'P1', 'Broken.', kind: FindingKind::MissingTest)->kindWords());
    }

    public function testAFindingWhoseTitleDiffersFromItsTextStillShowsTheWholeText(): void
    {
        self::assertTrue(new RoundFinding('F1', 'P1', 'Broken.', title: 'Discount lost')->goesOnAfterItsShortLine());
    }

    public function testAFindingWithATitleButNoRecordedTextDoesNotGoOnAfterItsShortLine(): void
    {
        self::assertSame([false, false], [
            new RoundFinding('F1', 'P1', null, title: 'Discount lost')->goesOnAfterItsShortLine(),
            new RoundFinding('F1', 'P1', "\u{00A0}", title: 'Discount lost')->goesOnAfterItsShortLine(),
        ]);
    }

    public function testAFindingsUrgencyReadsItsSeverityAsHowSoonToFixIt(): void
    {
        self::assertSame(['Urgent', 'Should fix', 'Nice to have', null, null], array_map(static fn (?string $severity): ?string => new RoundFinding('F1', $severity, 'A.')->urgency()?->words(), ['P1', 'P2', 'P3', 'P9', null]));
    }

    public function testAFindingsPlaceReadsFileColonLine(): void
    {
        self::assertSame('src/A.php:7', new RoundFinding('F1', 'P1', 'A.', [], 'src/A.php', 7)->place());
    }

    public function testAFindingWithAFileButNoLineNamesTheFileAlone(): void
    {
        self::assertSame('src/A.php', new RoundFinding('F1', 'P1', 'A.', file: 'src/A.php')->place());
    }

    public function testAFindingWithoutAFileHasNoPlace(): void
    {
        self::assertNull(new RoundFinding('F1', 'P1', 'A.', line: 7)->place());
    }

    public function testAQuoteWithARecordedEntryHasThatEntrysNearLines(): void
    {
        $line = new NearLine('src/A.php', 2, 'hostName();');

        self::assertSame([$line], $this->droppedWith(new QuoteNearLines('otherGhost', []), new QuoteNearLines('ghostName', [$line]))->nearLinesOf('ghostName'));
    }

    public function testAQuoteWithNoRecordedEntryHasNoNearLines(): void
    {
        self::assertSame([], $this->droppedWith(new QuoteNearLines('ghostName', [new NearLine('src/A.php', 2, 'hostName();')]))->nearLinesOf('ghost'));
    }

    public function testALongQuoteAndAQuoteEqualToItsFirstPartEachHaveTheirOwnNearLines(): void
    {
        $short = 'ghostName'.str_repeat('.', 151);
        $long = $short.' + phantomWidget';
        $forLong = new NearLine('src/B.php', 4, 'phantomWidgetReal();');
        $forShort = new NearLine('src/A.php', 2, 'ghostTitle();');
        $finding = $this->droppedWith(new QuoteNearLines($long, [$forLong]), new QuoteNearLines($short, [$forShort]));

        self::assertSame([[$forLong], [$forShort]], [$finding->nearLinesOf($long), $finding->nearLinesOf($short)]);
    }

    private function droppedWith(QuoteNearLines ...$nearest): RoundFinding
    {
        return new RoundFinding('F1', 'P2', 'Call `ghostName`.', nearest: array_values($nearest));
    }
}
