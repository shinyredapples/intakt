<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(JourneyPage::class)]
#[CoversClass(JourneyOutput::class)]
#[CoversClass(GlanceTable::class)]
#[CoversClass(MilestoneTable::class)]
#[UsesClass(GitHubReader::class)]
#[UsesClass(RecordedApi::class)]
#[UsesClass(UsageReader::class)]
#[UsesClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(UsageVendor::class)]
#[UsesClass(MarkedRegions::class)]
#[UsesClass(JourneyResource::class)]
#[UsesClass(JourneyDates::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Milestone::class)]
#[UsesClass(PullRequest::class)]
#[UsesClass(PageRegion::class)]
#[UsesClass(TokenCharts::class)]
#[UsesClass(IssueCharts::class)]
#[UsesClass(DayRange::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(ChartForm::class)]
#[UsesClass(ChartRegion::class)]
#[UsesClass(ChartCanvas::class)]
#[UsesClass(ChartPlot::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartPalette::class)]
#[UsesClass(YScale::class)]
#[UsesClass(StackedBarSvg::class)]
#[UsesClass(GroupedBarSvg::class)]
#[UsesClass(LineSvg::class)]
#[UsesClass(MermaidTimeline::class)]
#[UsesClass(Timeline::class)]
#[UsesClass(TimelineSection::class)]
#[UsesClass(TimelinePeriod::class)]
#[UsesClass(TimelineSvg::class)]
final class JourneyPageTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/Fixtures';

    public function testTheGlanceTableHoldsTheNumbersTheApiAndTheUsageFileGive(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString(<<<'MD'
            <!-- journey:begin glance -->
            | | |
            |---|---|
            | First merged pull request | 2026-10-26 |
            | Pull requests merged | 3 (to 2026-11-13) |
            | Issues closed | 5 (4 done, 1 dropped) |
            | Busiest day | 2 merges (2026-10-26) |
            | Tokens per merged pull request | 4.00M (12.00M tokens over 3 merges) |
            <!-- journey:end glance -->
            MD, $page);
    }

    public function testTheMilestoneTableHoldsOneRowPerMilestoneByDueDate(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString(<<<'MD'
            <!-- journey:begin milestones -->
            | Milestone | Goal | Due | Last issue closed | Done / open |
            |---|---|---|---|---|
            | M0 Unblock | fixes the build loop needed first | 10-27 | 11-05 | 2 / 0 |
            | M5 Demo | `docker compose up` \| the path | 11-21 | open | 1 / 1 |
            | M8 Switch |  | 2027-01-07 | open | 0 / 1 |
            <!-- journey:end milestones -->
            MD, $page);
    }

    public function testEachChartRegionShowsItsDrawingAndItsTable(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString(<<<'MD'
            <!-- journey:begin tokens-per-merge -->
            ![Tokens per merged pull request](journey/tokens-per-merge.svg)

            <details markdown="1"><summary>Tokens per merged pull request, as a table</summary>

            | Day | Tokens per merge |
            |---|---|
            | 10-26 | 2,000,000 |
            | 10-27 | 7,000,000 |
            | 10-28 | none |

            </details>
            <!-- journey:end tokens-per-merge -->
            MD, $page);
    }

    public function testTheChartsAndTheTimelineAreDrawnByName(): void
    {
        $drawings = $this->regenerated()->drawings;

        self::assertSame(['tokens-by-lane', 'tokens-by-account', 'tokens-per-merge', 'issues-per-day', 'open-issues', 'timeline'], array_keys($drawings));
        self::assertStringContainsString('Hackathon deadline 18:00 CET, repository public', $drawings['timeline']);
    }

    public function testTheTimelineBlockStaysAsItIsOnThePage(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString("```mermaid\ntimeline\n    title Intakt & friends\n", $page);
    }

    public function testARerunWithTheSameInputsChangesNothing(): void
    {
        $once = $this->regenerated();

        self::assertEquals($once, $this->regenerated($once->page));
    }

    public function testATokenTotalOfAMillionOrMoreIsWrittenInMillions(): void
    {
        $tokens = $this->regeneratedTokens(908_000_000, 614);

        self::assertStringContainsString('| Tokens per merged pull request | 1.48M (908M tokens over 614 merges) |', $tokens);
    }

    private function regeneratedTokens(int $total, int $merges): string
    {
        $table = new GlanceTable(new MockClock('2026-11-13')->now())->region([], array_fill(0, $merges, new PullRequest(new DateTimeImmutable('2026-10-26'))), $total);

        return $table->text;
    }

    private function regenerated(?string $page = null): JourneyOutput
    {
        $journey = new JourneyPage(new GitHubReader(new RecordedApi(self::FIXTURES.'/api')), new UsageReader(), new MockClock('2026-11-13 10:00:00'));

        return $journey->regenerated($page ?? (string) file_get_contents(self::FIXTURES.'/journey.md'), self::FIXTURES.'/usage.jsonl');
    }
}
