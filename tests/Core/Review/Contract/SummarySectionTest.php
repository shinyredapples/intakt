<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\SummarySection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SummarySection::class)]
final class SummarySectionTest extends TestCase
{
    private const string PAGE = <<<'MD'
        # Ledger

        ## In short

        The ledger keeps every event.

        ### Why

        It never forgets.

        ## Details

        Rows are appended.
        MD;

    public function testAChangedLineInsideTheSummaryTouchesIt(): void
    {
        self::assertTrue($this->changed(self::PAGE, str_replace('keeps every event', 'keeps each event', self::PAGE)));
    }

    public function testAChangedLineInASubsectionOfTheSummaryTouchesIt(): void
    {
        self::assertTrue($this->changed(self::PAGE, str_replace('never forgets', 'forgets nothing', self::PAGE)));
    }

    public function testAChangedLineAfterTheSummaryDoesNotTouchIt(): void
    {
        self::assertFalse($this->changed(self::PAGE, str_replace('Rows are appended', 'Rows are added', self::PAGE)));
    }

    public function testARemovedSummaryTouchesIt(): void
    {
        self::assertTrue($this->changed(self::PAGE, str_replace("## In short\n\nThe ledger keeps every event.\n\n", '', self::PAGE)));
    }

    public function testARenamedSummaryHeadingTouchesIt(): void
    {
        self::assertTrue($this->changed(self::PAGE, str_replace('## In short', '## Overview', self::PAGE)));
    }

    public function testAnAddedPageWithASummaryTouchesIt(): void
    {
        self::assertTrue($this->changed('', self::PAGE));
    }

    public function testADeletedPageWithASummaryTouchesIt(): void
    {
        self::assertTrue($this->changed(self::PAGE, ''));
    }

    public function testPagesWithoutASummaryNeverTouchOne(): void
    {
        self::assertFalse($this->changed("# Page\n\nOld text.\n", "# Page\n\nNew text.\n"));
    }

    public function testTheHeadingIsReadWhateverItsCaseAndClosingHashes(): void
    {
        $before = "# Page\n\n## in Short ##\n\nOld.\n";

        self::assertTrue($this->changed($before, str_replace('Old.', 'New.', $before)));
    }

    public function testAHeadingInsideACodeBlockIsNoSummary(): void
    {
        $before = "# Page\n\n```\n## In short\nOld.\n```\n";

        self::assertFalse($this->changed($before, str_replace('Old.', 'New.', $before)));
    }

    public function testAHeadingInsideACodeBlockDoesNotEndTheSummary(): void
    {
        $before = "## In short\n\n```\n## Not a heading\nOld.\n```\n";

        self::assertTrue($this->changed($before, str_replace('Old.', 'New.', $before)));
    }

    public function testWindowsLineEndsAreReadLikeOthers(): void
    {
        $before = "## In short\r\n\r\nOld.\r\n\r\n## Details\r\n\r\nRows.\r\n";

        self::assertFalse($this->changed($before, str_replace('Rows.', 'Lines.', $before)));
        self::assertTrue($this->changed($before, str_replace('Old.', 'New.', $before)));
    }

    private function changed(string $before, string $after): bool
    {
        return new SummarySection()->changedBetween($before, $after);
    }
}
