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
