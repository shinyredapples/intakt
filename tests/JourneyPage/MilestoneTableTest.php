<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MilestoneTable::class)]
#[UsesClass(MarkedRegions::class)]
#[UsesClass(JourneyDates::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Milestone::class)]
#[UsesClass(PageRegion::class)]
final class MilestoneTableTest extends TestCase
{
    private const string PAGE = "<!-- journey:begin milestones -->\nold\n<!-- journey:end milestones -->\n";

    public function testAMilestoneWithNothingOpenAndNothingDoneShowsNoneAsItsLastIssueClosed(): void
    {
        $closed = new DateTimeImmutable('2026-11-05T10:00:00Z');

        $table = new MilestoneTable()->region([new Milestone(1, 'All dropped', 'g', $closed), new Milestone(2, 'Empty', 'g', $closed)], [new Issue(1, $closed, true)]);

        self::assertStringContainsString("| All dropped | g | 11-05 | none | 0 / 0 |\n| Empty | g | 11-05 | none | 0 / 0 |", $table->text);
    }

    public function testAMilestoneWithAnOpenIssueShowsOpen(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', 'g', null)], [new Issue(1, null, false)]);

        self::assertStringContainsString('| Demo | g | none | open | 0 / 1 |', $table->text);
    }

    public function testMarkerTextInAMilestoneIsEscapedSoARerunChangesNothing(): void
    {
        $region = new MilestoneTable()->region([new Milestone(1, 'x <!-- journey:end milestones --> y', '<!-- journey:end milestones -->', null)], []);

        $once = new MarkedRegions()->replaced(self::PAGE, [$region]);

        self::assertSame($once, new MarkedRegions()->replaced($once, [$region]));
    }

    public function testAnAngleBracketInAMilestoneIsWrittenAsAnEntity(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'a <!-- b', 'c < d', null)], []);

        self::assertStringContainsString('| a &lt;!-- b | c &lt; d |', $table->text);
    }
}
