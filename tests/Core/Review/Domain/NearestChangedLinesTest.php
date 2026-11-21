<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Closure;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Domain\NearestChangedLines;
use Intakt\Core\Review\Domain\QuotesInChange;
use Intakt\Core\Review\Infrastructure\DiffAddedLines;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NearestChangedLines::class)]
#[CoversClass(QuoteNearLines::class)]
#[CoversClass(NearLine::class)]
#[UsesClass(QuotesInChange::class)]
#[UsesClass(DiffAddedLines::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Redaction::class)]
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

    public function testALongQuoteIsStoredCutToTheLengthCap(): void
    {
        $quote = 'dropReason('.str_repeat('a, ', 100).'b)';

        $nearest = $this->nearestFor('`'.$quote.'` is unused.', [['src/A.php', 1, '$reason = 1;']]);

        self::assertSame(mb_substr($quote, 0, NearestChangedLines::MAX_LINE_LENGTH), $nearest[0]->quote);
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

    public function testAChangedLineIsRedactedBeforeItIsCutSoACredentialAcrossTheCutLeavesNoPart(): void
    {
        $line = '$reason = \''.str_repeat('a', NearestChangedLines::MAX_LINE_LENGTH - 16).'held-secret-value\';';

        $text = $this->nearestFor('`dropReason` is unused.', [['src/A.php', 1, $line]], $this->redactingHeldSecret(...))[0]->lines[0]->text;

        self::assertStringNotContainsString('held', $text);
        self::assertStringEndsWith('[reda', $text);
    }

    public function testAQuoteIsStoredRedacted(): void
    {
        $nearest = $this->nearestFor('`dropReason(held-secret-value)` is unused.', [['src/A.php', 1, '$dropReason = $heldSecretValue;']], $this->redactingHeldSecret(...));

        self::assertSame('dropReason([redacted])', $nearest[0]->quote);
    }

    public function testAPrivateKeyBodyLineOfADiffIsNotRecorded(): void
    {
        $body = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASC7signing9Key+x';
        $dashes = str_repeat('-', 5);
        $diff = "diff --git a/jwt.pem b/jwt.pem\n--- /dev/null\n+++ b/jwt.pem\n@@ -0,0 +1,3 @@\n+".$dashes.'BEGIN PRIVATE KEY'.$dashes."\n+".$body."\n+".$dashes.'END PRIVATE KEY'.$dashes."\n";

        $nearest = $this->nearestFor('`signingKey` is never rotated.', new DiffAddedLines(new DiffPaths())->in($diff), new Redaction()->redact(...));

        self::assertSame([], $nearest);
    }

    public function testALineIsStoredAsItStandsInItsRedactedHunk(): void
    {
        $hunk = "context\n\$reason = 'held-secret-value';\ncontext";

        $nearest = $this->nearestFor('`dropReason` is unused.', [['src/A.php', 2, "\$reason = 'held-secret-value';", $hunk, 1]], $this->redactingHeldSecret(...));

        self::assertEquals([new NearLine('src/A.php', 2, "\$reason = '[redacted]';")], $nearest[0]->lines);
    }

    public function testALineLeftOutWithItsHunkTakesNoPlaceAmongTheKeptLines(): void
    {
        $leftOut = ['src/A.php', 1, '$dropReason = 0;', "\$dropReason = 0;\nheld-secret\n-value", 0];
        $kept = array_map(static fn (int $line): array => ['src/B.php', $line, '$reason = '.$line.';'], range(1, 3));

        $nearest = $this->nearestFor('`dropReason` is unused.', [$leftOut, ...$kept], static fn (string $text): string => str_replace("held-secret\n-value", '[redacted]', $text));

        self::assertSame([['src/B.php', 1], ['src/B.php', 2], ['src/B.php', 3]], $this->placesOf($nearest[0]));
    }

    public function testAHeldCredentialWithBytesThatAreNotUtf8IsRedacted(): void
    {
        $secret = "credential\xFFsecretFixture";

        $nearest = $this->nearestFor('`customerName` is missing.', [['src/A.php', 1, '$customer = "'.$secret.'";']], static fn (string $text): string => str_replace($secret, '[redacted]', $text));

        self::assertSame('$customer = "[redacted]";', $nearest[0]->lines[0]->text);
    }

    public function testALineInAFileWhosePathHoldsACredentialIsLeftOut(): void
    {
        $nearest = $this->nearestFor('`dropReason` is unused.', [['src/held-secret-value.php', 1, '$reason = 1;'], ['src/B.php', 2, '$reason = 2;']], $this->redactingHeldSecret(...));

        self::assertSame([['src/B.php', 2]], $this->placesOf($nearest[0]));
    }

    public function testAQuoteInAFencedBlockIsNotLookedFor(): void
    {
        self::assertSame([], $this->nearestFor("Fix:\n```\n\$dropReason = 1;\n```\n", [['src/A.php', 1, '$reason = 1;']]));
    }

    /**
     * @param list<array{0: string, 1: int, 2: string, 3?: string, 4?: int}> $changedLines
     * @param ?Closure(string): string                                       $redact
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
