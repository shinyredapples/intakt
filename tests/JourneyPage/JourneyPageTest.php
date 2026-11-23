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
#[UsesClass(CountedMerges::class)]
#[UsesClass(IssueCharts::class)]
#[UsesClass(DayRange::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(FigureSource::class)]
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
            | Pull requests merged | 3 counted (to 2026-11-13) |
            | Issues closed | 5 counted (4 done, 1 dropped) |
            | Busiest day | 2 counted merges (2026-10-26) |
            | Tokens per merged pull request | 4.00M measured (12.00M measured tokens over 3 counted merges; not included: 500000 estimated tokens reserved for killed steps) |

            Counted: from the pull requests and issues the GitHub API lists. Measured: the providers' usage answers, summed per day from the `tokens` field of `docs/journey/usage.jsonl`. Estimated: the reservations of steps that were killed before they reported usage, from the `reserved` field of `docs/journey/usage.jsonl` and never added to the measured tokens.
            <!-- journey:end glance -->
            MD, $page);
    }

    public function testTokensReservedForKilledStepsStandBesideTheMeasuredTokensNeverInThem(): void
    {
        $tokens = $this->regeneratedTokens(908_000_000, 614, 21_000_000);

        self::assertStringContainsString('| Tokens per merged pull request | 1.48M measured (908M measured tokens over 614 counted merges; not included: 21.00M estimated tokens reserved for killed steps) |', $tokens);
        self::assertStringContainsString('Estimated: the reservations of steps', $tokens);
    }

    public function testWithoutReservedTokensTheGlanceTableNamesNoEstimatedFigure(): void
    {
        $tokens = $this->regeneratedTokens(500, 2);

        self::assertStringNotContainsString('estimated', $tokens);
        self::assertStringNotContainsString('Estimated', $tokens);
    }

    public function testTheMilestoneTableHoldsOneRowPerMilestoneByDueDate(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString(<<<'MD'
            <!-- journey:begin milestones -->
            | Milestone | Goal | Due | Last issue closed | Done / open (counted) |
            |---|---|---|---|---|
            | M0 Unblock | fixes the build loop needed first | 10-27 | 11-05 | 2 / 0 |
            | M5 Demo | \`docker compose up\` \| the path | 11-21 | open | 1 / 1 |
            | M8 Switch |  | 2027-01-07 | open | 0 / 1 |

            Counted: from the issues the GitHub API lists, by each issue's milestone.
            <!-- journey:end milestones -->
            MD, $page);
    }

    public function testEachChartRegionShowsItsDrawingAndItsTable(): void
    {
        $page = $this->regenerated()->page;

        self::assertStringContainsString(<<<'MD'
            <!-- journey:begin tokens-per-merge -->
            ![Tokens per merged pull request (measured)](journey/tokens-per-merge.svg)

            Measured: the day's tokens from the providers' usage answers, summed per day from the `tokens` field of `docs/journey/usage.jsonl`, divided by the pull requests merged that day, counted from the GitHub API.

            <details markdown="1"><summary>Tokens per merged pull request (measured), as a table</summary>

            | Day | Tokens per merge (measured) |
            |---|---|
            | 10-26 | 2,000,000 |
            | 10-27 | 7,000,000 |
            | 10-28 | none |

            </details>
            <!-- merges counted for usage sha256:4c57f6d07fef244adbbf6703f2b1369f256c7082783c26ff3682154ab813fecc: 2026-10-26=2 2026-10-27=1 -->
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

    public function testAKeptMergeCountOfZeroCountsTheMergesAgain(): void
    {
        $digest = (string) hash_file('sha256', self::FIXTURES.'/usage.jsonl');
        $kept = '<!-- merges counted for usage sha256:'.$digest.': 2026-10-26=0 2026-10-27=1 -->';
        $page = str_replace('<!-- journey:end tokens-per-merge -->', $kept."\n".'<!-- journey:end tokens-per-merge -->', (string) file_get_contents(self::FIXTURES.'/journey.md'));

        $regenerated = $this->regenerated($page)->page;

        self::assertStringContainsString('<!-- merges counted for usage sha256:'.$digest.': 2026-10-26=2 2026-10-27=1 -->', $regenerated);
        self::assertStringContainsString('| 10-26 | 2,000,000 |', $regenerated);
    }

    public function testATokenTotalOfAMillionOrMoreIsWrittenInMillions(): void
    {
        $tokens = $this->regeneratedTokens(908_000_000, 614);

        self::assertStringContainsString('| Tokens per merged pull request | 1.48M measured (908M measured tokens over 614 counted merges) |', $tokens);
    }

    public function testTheGlanceTableShowsMeasuredTokensBesideCountedMergesNeverAsOneUnlabeledFigure(): void
    {
        $tokens = $this->regeneratedTokens(500, 2);

        self::assertStringContainsString('| Tokens per merged pull request | 250 measured (500 measured tokens over 2 counted merges) |', $tokens);
    }

    public function testEveryCountRowOfTheGlanceTableCarriesTheCountedMarker(): void
    {
        $tokens = $this->regeneratedTokens(500, 2);

        self::assertStringContainsString('| Pull requests merged | 2 counted (to 2026-11-13) |', $tokens);
        self::assertStringContainsString('| Issues closed | 0 counted (0 done, 0 dropped) |', $tokens);
        self::assertStringContainsString('| Busiest day | 2 counted merges (2026-10-26) |', $tokens);
    }

    public function testATableWithoutMergesShowsNoTokenFigureToLabel(): void
    {
        $tokens = $this->regeneratedTokens(500, 0);

        self::assertStringContainsString('| Tokens per merged pull request | none yet |', $tokens);
    }

    private function regeneratedTokens(int $total, int $merges, int $reserved = 0): string
    {
        $usage = new Usage([new TokenUsage(new DateTimeImmutable('2026-10-26'), UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, $total, $reserved)]);
        $table = new GlanceTable(new MockClock('2026-11-13')->now())->region([], array_fill(0, $merges, new PullRequest(new DateTimeImmutable('2026-10-26'))), $usage);

        return $table->text;
    }

    private function regenerated(?string $page = null): JourneyOutput
    {
        $journey = new JourneyPage(new GitHubReader(new RecordedApi(self::FIXTURES.'/api')), new UsageReader(), new MockClock('2026-11-13 10:00:00'));

        return $journey->regenerated($page ?? (string) file_get_contents(self::FIXTURES.'/journey.md'), self::FIXTURES.'/usage.jsonl');
    }
}
