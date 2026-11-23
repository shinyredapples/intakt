<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Closure;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Domain\NearestChangedLines;
use Intakt\Core\Review\Domain\QuotesInChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NearestChangedLines::class)]
#[CoversClass(QuoteNearLines::class)]
#[CoversClass(NearLine::class)]
#[UsesClass(QuotesInChange::class)]
final class NearestChangedLinesTest extends TestCase
{
    public function testAQuoteSharingNamePartsWithAChangedLineRecordsThatLineWithItsFileAndNumber(): void
    {
        $nearest = $this->nearestFor('`reasonForDrop()` is never called.', [['src/A.php', 12, '    $reason = $quotes->reasonToDrop($finding);']]);

        self::assertEquals([new QuoteNearLines('reasonForDrop()', [new NearLine('src/A.php', 12, '$reason = $quotes->reasonToDrop($finding);')])], $nearest);
    }

    public function testAQuoteWhoseNamePartsAppearOnNoChangedLineRecordsNoLine(): void
    {
        self::assertSame([], $this->nearestFor('`grepName` is not defined.', [['src/A.php', 1, 'new'], ['src/A.php', 2, '$total = 3;']]));
    }

    public function testALineSharingFewerThanHalfOfTheQuotesNamePartsDoesNotComeClose(): void
    {
        self::assertSame([], $this->nearestFor('`countedQuoteFragments` is wrong.', [['src/A.php', 1, '$counted = 1;']]));
    }

    public function testALineSharingHalfOfTheQuotesNamePartsComesClose(): void
    {
        $nearest = $this->nearestFor('`countedFragments` is wrong.', [['src/A.php', 1, '$counted = 1;']]);

        self::assertEquals([new QuoteNearLines('countedFragments', [new NearLine('src/A.php', 1, '$counted = 1;')])], $nearest);
    }

    public function testALineSharingMoreNamePartsWithTheQuoteRanksBeforeOneSharingFewer(): void
    {
        $nearest = $this->nearestFor('`dropReasonText` is unused.', [['src/A.php', 1, '$drop = $reason;'], ['src/B.php', 9, '$dropReason = $text;']]);

        self::assertSame([['src/B.php', 9], ['src/A.php', 1]], $this->placesOf($nearest[0]));
    }

    public function testLinesSharingEquallyKeepTheirOrderInTheDiff(): void
    {
        $nearest = $this->nearestFor('`dropReason` is unused.', [['src/B.php', 9, '$drop = 1;'], ['src/A.php', 1, '$reason = 2;']]);

        self::assertSame([['src/B.php', 9], ['src/A.php', 1]], $this->placesOf($nearest[0]));
    }

    public function testAtMostThreeLinesAreKeptPerQuote(): void
    {
        $lines = array_map(static fn (int $line): array => ['src/A.php', $line, '$reason = '.$line.';'], range(1, 5));

        self::assertSame([['src/A.php', 1], ['src/A.php', 2], ['src/A.php', 3]], $this->placesOf($this->nearestFor('`dropReason` is unused.', $lines)[0]));
    }

    public function testALongChangedLineIsStoredCutToTheLengthCap(): void
    {
        $line = '$reason = \''.str_repeat('é', 300).'\';';

        $text = $this->nearestFor('`dropReason` is unused.', [['src/A.php', 1, $line]])[0]->lines[0]->text;

        self::assertSame(mb_substr($line, 0, NearestChangedLines::MAX_LINE_LENGTH), $text);
    }

    public function testALongQuoteIsStoredWholeSoItStaysTheKeyOfItsLines(): void
    {
        $quote = 'dropReason('.str_repeat('a, ', 100).'b)';

        $nearest = $this->nearestFor('`'.$quote.'` is unused.', [['src/A.php', 1, '$reason = 1;']]);

        self::assertSame($quote, $nearest[0]->quote);
    }

    public function testAQuoteLongerThanTheLengthCapDoesNotShareItsKeyWithAQuoteEqualToItsFirstPart(): void
    {
        $short = 'ghostName'.str_repeat('.', 151);
        $long = $short.' + phantomWidget';

        $nearest = $this->nearestFor('Try `'.$long.'` and `'.$short.'`.', [['src/A.php', 2, 'ghostTitle();'], ['src/B.php', 4, 'phantomWidgetReal();']]);

        self::assertSame([$long, $short], array_map(static fn (QuoteNearLines $near): string => $near->quote, $nearest));
        self::assertSame('src/B.php', $nearest[0]->lines[0]->file);
        self::assertSame('src/A.php', $nearest[1]->lines[0]->file);
    }

    public function testANamePartInsideALongerWordIsNotShared(): void
    {
        self::assertSame([], $this->nearestFor('`dropReason` is unused.', [['src/A.php', 1, '$dropped = $reasonable;']]));
    }

    public function testAShortNamePartIsNeverShared(): void
    {
        self::assertSame([], $this->nearestFor('`idOf` is unused.', [['src/A.php', 1, '$id = of();']]));
    }

    public function testEachCountedQuoteGetsItsOwnLines(): void
    {
        $nearest = $this->nearestFor('`dropReason` and `ghostTotal` are unused.', [['src/A.php', 1, '$reason = 1;'], ['src/A.php', 2, '$total = 2;']]);

        self::assertSame(['dropReason', 'ghostTotal'], array_map(static fn (QuoteNearLines $quote): string => $quote->quote, $nearest));
        self::assertSame([['src/A.php', 2]], $this->placesOf($nearest[1]));
    }

    public function testEveryCountedQuoteOfAFindingWithManyQuotesGetsItsLines(): void
    {
        $text = '`reasonOne` `reasonTwo` `reasonThree` `reasonFour` `reasonFive` `reasonSix`';

        self::assertCount(6, $this->nearestFor($text, [['src/A.php', 1, '$reason = 1;']]));
    }

    public function testALineInAFileWhosePathIsNotUtf8IsLeftOut(): void
    {
        $nearest = $this->nearestFor('`dropReason` is unused.', [["src/A\xFF.php", 1, '$reason = 1;'], ['src/B.php', 2, '$reason = 2;']]);

        self::assertSame([['src/B.php', 2]], $this->placesOf($nearest[0]));
    }

    public function testAQuoteIsStoredRedacted(): void
    {
        $nearest = $this->nearestFor('`dropReason(held-secret-value)` is unused.', [['src/A.php', 1, '$dropReason = $heldSecretValue;']], $this->redactingHeldSecret(...));

        self::assertSame('dropReason([redacted])', $nearest[0]->quote);
    }

    public function testAQuoteTheFindingRepeatsIsRecordedOnce(): void
    {
        $nearest = $this->nearestFor('`dropReason` is unused, and `dropReason` is never read.', [['src/A.php', 1, '$reason = 1;']]);

        self::assertEquals([new QuoteNearLines('dropReason', [new NearLine('src/A.php', 1, '$reason = 1;')])], $nearest);
    }

    public function testALineWithBytesThatAreNotUtf8IsStoredScrubbed(): void
    {
        $nearest = $this->nearestFor('`customerName` is missing.', [['src/A.php', 1, "\$customer = \"a\xFFb\";"]]);

        self::assertSame('$customer = "a?b";', $nearest[0]->lines[0]->text);
    }

    public function testALineInAFileWhosePathHoldsACredentialIsLeftOut(): void
    {
        $nearest = $this->nearestFor('`dropReason` is unused.', [['src/held-secret-value.php', 1, '$reason = 1;'], ['src/B.php', 2, '$reason = 2;']], $this->redactingHeldSecret(...));

        self::assertSame([['src/B.php', 2]], $this->placesOf($nearest[0]));
    }

    public function testARedactionMarkerDoesNotCountAsANameInTheChange(): void
    {
        self::assertSame([], $this->nearestFor('`redactedResult` is missing.', [['src/A.php', 1, '$thing = "'.Redaction::MARKER.'";']]));
    }

    public function testALineHoldingOnlyARedactionMarkerDoesNotComeCloseToAQuoteNamingRedacted(): void
    {
        self::assertSame([], $this->nearestFor('`redacted` is missing.', [['config/a.env', 1, Redaction::MARKER]]));
    }

    public function testALineWithARedactionMarkerStillComesCloseThroughASharedNameAndKeepsTheMarker(): void
    {
        $line = '$redactedResult = "'.Redaction::MARKER.'";';

        $nearest = $this->nearestFor('`redactedResult` is missing.', [['src/A.php', 1, $line]]);

        self::assertEquals([new QuoteNearLines('redactedResult', [new NearLine('src/A.php', 1, $line)])], $nearest);
    }

    public function testNamesOnBothSidesOfARedactionMarkerStayApart(): void
    {
        self::assertSame([], $this->nearestFor('`dropreason` is missing.', [['src/A.php', 1, 'drop'.Redaction::MARKER.'reason']]));
    }

    public function testAQuoteInAFencedBlockIsNotLookedFor(): void
    {
        self::assertSame([], $this->nearestFor("Fix:\n```\n\$dropReason = 1;\n```\n", [['src/A.php', 1, '$reason = 1;']]));
    }

    /**
     * @param list<array{string, int, string}> $changedLines
     * @param ?Closure(string): string         $redact
     *
     * @return list<QuoteNearLines>
     */
    private function nearestFor(string $findingText, array $changedLines, ?Closure $redact = null): array
    {
        return new NearestChangedLines($redact ?? static fn (string $text): string => $text)->forQuotesOf($findingText, $changedLines);
    }

    private function redactingHeldSecret(string $text): string
    {
        return str_replace('held-secret-value', '[redacted]', $text);
    }

    /**
     * @return list<array{string, int}>
     */
    private function placesOf(QuoteNearLines $quote): array
    {
        return array_map(static fn (NearLine $line): array => [$line->file, $line->line], $quote->lines);
    }
}
