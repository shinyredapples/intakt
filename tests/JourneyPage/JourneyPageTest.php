<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(JourneyPage::class)]
#[CoversClass(GlanceTable::class)]
#[CoversClass(MilestoneTable::class)]
#[UsesClass(GitHubReader::class)]
#[UsesClass(RecordedApi::class)]
#[UsesClass(UsageReader::class)]
#[UsesClass(MarkedRegions::class)]
#[UsesClass(JourneyResource::class)]
#[UsesClass(JourneyDates::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Milestone::class)]
#[UsesClass(PullRequest::class)]
#[UsesClass(PageRegion::class)]
final class JourneyPageTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/Fixtures';

    public function testTheGlanceTableHoldsTheNumbersTheApiAndTheUsageFileGive(): void
    {
        $page = $this->regenerated();

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
        $page = $this->regenerated();

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

    public function testARerunWithTheSameInputsChangesNothing(): void
    {
        $once = $this->regenerated();

        self::assertSame($once, $this->regenerated($once));
    }

    public function testATokenTotalOfAMillionOrMoreIsWrittenInMillions(): void
    {
        $tokens = $this->regeneratedTokens(908_000_000, 614);

        self::assertStringContainsString('| Tokens per merged pull request | 1.48M (908M tokens over 614 merges) |', $tokens);
    }

    private function regeneratedTokens(int $total, int $merges): string
    {
        $table = new GlanceTable()->region([], array_fill(0, $merges, new PullRequest(new DateTimeImmutable('2026-10-26'))), $total, new MockClock('2026-11-13')->now());

        return $table->text;
    }

    private function regenerated(?string $page = null): string
    {
        $journey = new JourneyPage(new GitHubReader(new RecordedApi(self::FIXTURES.'/api')), new UsageReader(), new MockClock('2026-11-13 10:00:00'));

        return $journey->regenerated($page ?? (string) file_get_contents(self::FIXTURES.'/journey.md'), self::FIXTURES.'/usage.jsonl');
    }
}
