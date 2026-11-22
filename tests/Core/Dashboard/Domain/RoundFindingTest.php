<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundFinding::class)]
#[CoversClass(FindingUrgency::class)]
#[UsesClass(NearLine::class)]
#[UsesClass(QuoteNearLines::class)]
final class RoundFindingTest extends TestCase
{
    public function testAFindingsHeadlineIsItsFirstSentence(): void
    {
        self::assertSame('The total in src/A.php is off by 1.5.', new RoundFinding('F1', 'P1', 'The total in src/A.php is off by 1.5. It drops the last item.')->headline());
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
        self::assertTrue(new RoundFinding('F1', 'P2', 'Keep `Ready? yes` as the prompt. More.')->goesOnAfterItsHeadline());
    }

    public function testAFindingOfOneSentenceOrWithoutTextDoesNotGoOnAfterItsHeadline(): void
    {
        self::assertSame([false, false, false], [
            new RoundFinding('F1', 'P2', " Keep `Ready? yes` as the prompt.\u{2003}")->goesOnAfterItsHeadline(),
            new RoundFinding('F1', 'P2', "\u{00A0}")->goesOnAfterItsHeadline(),
            new RoundFinding('F1', 'P2', null)->goesOnAfterItsHeadline(),
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
