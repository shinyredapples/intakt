<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundFinding::class)]
#[UsesClass(NearLine::class)]
#[UsesClass(QuoteNearLines::class)]
final class RoundFindingTest extends TestCase
{
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

    public function testAFindingsHeadlineIsTheFirstSentenceAndTheRestExplainsIt(): void
    {
        $finding = new RoundFinding('F1', 'P1', 'The total in src/A.php is off by 1.5. It drops the last item.');

        self::assertSame(['The total in src/A.php is off by 1.5.', 'It drops the last item.'], [$finding->headline(), $finding->explanation()]);
    }

    public function testAFindingsHeadlineEndsAtItsFirstLine(): void
    {
        $finding = new RoundFinding('F1', 'P2', "No test covers Cart::total\nAdd one for an empty cart.");

        self::assertSame(['No test covers Cart::total', 'Add one for an empty cart.'], [$finding->headline(), $finding->explanation()]);
    }

    public function testAOneSentenceFindingHasNoExplanation(): void
    {
        self::assertNull(new RoundFinding('F1', 'P2', 'The total is off by one.')->explanation());
    }

    public function testAFindingWithoutTextHasNoHeadline(): void
    {
        self::assertSame([null, null], [new RoundFinding('F1', null, null)->headline(), new RoundFinding('F1', null, null)->explanation()]);
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
