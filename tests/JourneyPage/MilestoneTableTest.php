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
#[UsesClass(FigureSource::class)]
final class MilestoneTableTest extends TestCase
{
    private const string PAGE = "<!-- journey:begin milestones -->\nold\n<!-- journey:end milestones -->\n";

    public function testAMilestoneWithNothingOpenAndNothingDoneShowsNoneAsItsLastIssueClosed(): void
    {
        $closed = new DateTimeImmutable('2026-11-05T10:00:00Z');

        $table = new MilestoneTable()->region([new Milestone(1, 'All dropped', 'g', $closed), new Milestone(2, 'Empty', 'g', $closed)], [new Issue(1, $closed, $closed, true)]);

        self::assertStringContainsString("| All dropped | g | 11-05 | none | 0 / 0 |\n| Empty | g | 11-05 | none | 0 / 0 |", $table->text);
    }

    public function testAMilestoneWithAnOpenIssueShowsOpen(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', 'g', null)], [new Issue(1, new DateTimeImmutable('2026-11-01T10:00:00Z'), null, false)]);

        self::assertStringContainsString('| Demo | g | none | open | 0 / 1 |', $table->text);
    }

    public function testTheDoneAndOpenCountsAreLabeledCountedAndNameTheirSource(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', 'g', null)], []);

        self::assertStringStartsWith('| Milestone | Goal | Due | Last issue closed | Done / open (counted) |', $table->text);
        self::assertStringEndsWith("| Demo | g | none | none | 0 / 0 |\n\nCounted: from the issues the GitHub API lists, by each issue's milestone.", $table->text);
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

        self::assertStringContainsString('| a &lt;\!\-\- b | c &lt; d |', $table->text);
    }

    public function testMarkdownInAMilestoneGoalIsWrittenEscapedAndNeverRaw(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', '[label](#anchor){: title="x"} ![img](x.png) *em* `code`', null)], []);

        self::assertStringContainsString('| Demo | \[label\]\(\#anchor\)\{\: title=\"x\"\} \!\[img\]\(x\.png\) \*em\* \`code\` |', $table->text);
        foreach (['[label](#anchor)', '{: title="x"}', '![img](x.png)', '*em*', '`code`'] as $raw) {
            self::assertStringNotContainsString($raw, $table->text);
        }
    }

    public function testABackslashInAMilestoneTitleIsEscapedOnceBeforeThePunctuationAfterIt(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'a\*b', 'g', null)], []);

        self::assertStringContainsString('| a\\\\\*b | g |', $table->text);
    }

    public function testEveryListedPunctuationCharacterInAMilestoneTitleIsEscaped(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, '\`*_{}[]()#+-.!:|"\'$>', 'g', null)], []);

        self::assertStringContainsString('| \\\\\`\*\_\{\}\[\]\(\)\#\+\-\.\!\:\|\"\\\'\$\> | g |', $table->text);
    }

    public function testPunctuationOutsideTheListIsKeptAsItIs(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, ' a=b, c; d? ', 'g', null)], []);

        self::assertStringContainsString('| a=b, c; d? | g |', $table->text);
    }

    public function testEachLineBreakInAMilestoneTitleBecomesOneSpace(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, "a\r\nb\rc\nd", 'g', null)], []);

        self::assertStringContainsString('| a b c d | g |', $table->text);
    }

    public function testNonAsciiTextInAMilestoneTitleAndGoalIsKeptByteForByte(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Ångström Ålesund ąх…', 'Выход Wąż 😅', null)], []);

        self::assertStringContainsString('| Ångström Ålesund ąх… | Выход Wąż 😅 |', $table->text);
        self::assertTrue(mb_check_encoding($table->text, 'UTF-8'));
    }

    public function testATildeInAMilestoneGoalIsWrittenAsAnEntitySoItCannotStrikeThrough(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', '~~draft~~', null)], []);

        self::assertStringContainsString('| Demo | &#126;&#126;draft&#126;&#126; |', $table->text);
        self::assertStringNotContainsString('~', $table->text);
    }

    public function testAnAmpersandInAMilestoneGoalIsWrittenAsAnEntitySoNoEntityRenders(): void
    {
        $table = new MilestoneTable()->region([new Milestone(1, 'Demo', '&copy; &quot; a < b', null)], []);

        self::assertStringContainsString('| Demo | &amp;copy; &amp;quot; a &lt; b |', $table->text);
    }
}
