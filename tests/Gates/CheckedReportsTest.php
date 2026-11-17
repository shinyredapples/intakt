<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckedReports;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\Mode;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Scope;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CheckedReports::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(ProcessRunner::class)]
final class CheckedReportsTest extends SandboxTestCase
{
    private const string NO_CHECKED_LINE = 'upstream/a-bug/REPORT.md is ready to file but has no "Checked" line';

    private const string STRAY_STATUS = 'upstream/a-bug/REPORT.md has a ready to file status outside the top-level lines of its Upstream status section: write the status as a list item in the Upstream status section';

    private const string PULL_REQUESTS = "- **Pull requests and commits:** none found (checked 2026-11-07).\n";

    private const string NO_PULL_REQUESTS_LINE = 'upstream/a-bug/REPORT.md is ready to file but has no "Pull requests and commits" line (the pull requests and commits the check found, or "none found" with the date of the check)';

    public function testAReadyToFileReportCheckedFourteenDaysAgoPasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-10-24 against main.\n".self::PULL_REQUESTS."- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWithoutAPullRequestsAndCommitsLineIsAFinding(): void
    {
        self::assertSame([self::NO_PULL_REQUESTS_LINE], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineNamesAPullRequestPasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** #1857 (open) touches the same file.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineIsEmptyIsAFinding(): void
    {
        self::assertSame([self::NO_PULL_REQUESTS_LINE], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:**\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundWithoutADateIsAFinding(): void
    {
        $findings = $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** `None found`.\n- **Status:** `ready to file`\n");

        self::assertSame(['upstream/a-bug/REPORT.md is ready to file but its "Pull requests and commits" line says "none found" without the date of the check as YYYY-MM-DD'], $findings);
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundInUnderscoreEmphasisWithoutADateIsAFinding(): void
    {
        $findings = $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** _none found_.\n- **Status:** `ready to file`\n");

        self::assertSame(['upstream/a-bug/REPORT.md is ready to file but its "Pull requests and commits" line says "none found" without the date of the check as YYYY-MM-DD'], $findings);
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundInUnderscoreEmphasisWithADatePasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** __none found__ (checked 2026-11-07).\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineNamesAWordStartingWithNoneFoundNeedsNoDate(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** none foundry commits, see pull request 12.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundWithAnImpossibleDateIsAFinding(): void
    {
        $findings = $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** none found (checked 2026-02-30).\n- **Status:** `ready to file`\n");

        self::assertStringContainsString('says "none found" without the date of the check', implode("\n", $findings));
    }

    public function testAPullRequestsAndCommitsLineInAnotherSectionDoesNotSatisfyAReadyReport(): void
    {
        self::assertSame([self::NO_PULL_REQUESTS_LINE], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Status:** `ready to file`\n\n## Notes\n\n".self::PULL_REQUESTS));
    }

    public function testAReadyToFileReportWithoutACheckedLineNamesTheCheckedLineFirst(): void
    {
        self::assertSame([self::NO_CHECKED_LINE.' (date, upstream commit or tag, duplicate search, preferred form and target branch)'], $this->findingsOf("- **Status:** `ready to file`\n"));
    }

    public function testAReportRetiredByALaterNotFiledStatusNeedsNoPullRequestsAndCommitsLine(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Status:** `ready to file`\n- **Status:** `not filed` (owner review).\n"));
    }

    public function testAReadyToFileReportCheckedFifteenDaysAgoFails(): void
    {
        self::assertStringContainsString('older than 14 days (2026-10-23)', implode("\n", $this->findingsOf("- **Checked:** 2026-10-23 against main.\n- **Status:** `ready to file`\n")));
    }

    public function testASecondUpstreamStatusSectionIsAFinding(): void
    {
        $findings = $this->findingsOf("- **Status:** `not filed`\n\n## Upstream status\n\n- **Status:** `not filed`\n");

        self::assertSame(['upstream/a-bug/REPORT.md has more than one Upstream status section'], $findings);
    }

    public function testAnIndentedNotFiledExampleDoesNotRetireAReadyReport(): void
    {
        self::assertSame([self::NO_CHECKED_LINE.' (date, upstream commit or tag, duplicate search, preferred form and target branch)'], $this->findingsOf("- **Status:** `ready to file`\n\n    **Status:** not filed\n"));
    }

    public function testABlockquotedNotFiledLineDoesNotRetireAReadyReport(): void
    {
        self::assertStringContainsString(self::NO_CHECKED_LINE, implode("\n", $this->findingsOf("- **Status:** `ready to file`\n\n> - **Status:** `not filed`\n")));
    }

    public function testANotFiledStatusIndentedThreeSpacesRetiresAReadyReport(): void
    {
        self::assertSame([], $this->findingsOf("- **Status:** `ready to file`\n   - **Status:** `not filed`\n"));
    }

    public function testAReadyToFileStatusInAnotherSectionIsAFinding(): void
    {
        self::assertSame([self::STRAY_STATUS], $this->findingsOf("The owner decides.\n\n## Notes\n\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileStatusInABlockquoteIsAFinding(): void
    {
        self::assertSame([self::STRAY_STATUS], $this->findingsOf("- **Status:** `not filed`\n\n> **Status:** `ready to file`\n"));
    }

    public function testAnIndentedReadyToFileStatusIsAFinding(): void
    {
        self::assertSame([self::STRAY_STATUS], $this->findingsOf("- **Status:** `not filed`\n\n    **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileStatusInAFencedCodeBlockOfAnotherSectionIsNoFinding(): void
    {
        self::assertSame([], $this->findingsOf("- **Status:** `not filed`\n\n## Notes\n\n```\n- **Status:** `ready to file`\n```\n"));
    }

    /**
     * @return list<string>
     */
    private function findingsOf(string $statusSection): array
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n\n## Upstream status\n\n".$statusSection);
        $reports = new CheckedReports(new FencedCodeBlocks(), new MockClock('2026-11-07 12:00:00', 'UTC'));

        return $reports->findings(new Workspace($sandbox->root, $sandbox->root, Mode::Full, null), ['a-bug']);
    }
}
