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

    private const string NAMES_NOTHING = 'upstream/a-bug/REPORT.md is ready to file but its "Pull requests and commits" line names no pull request (#n), URL or commit hash and no date of the check as YYYY-MM-DD';

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
        self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** `None found`.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundInUnderscoreEmphasisWithoutADateIsAFinding(): void
    {
        self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** _none found_.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundInUnderscoreEmphasisWithADatePasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** __none found__ (checked 2026-11-07).\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundWithAnImpossibleDateIsAFinding(): void
    {
        self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** none found (checked 2026-02-30).\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineNamesNothingElseThanAWordIsAFinding(): void
    {
        foreach (['none', '-', '``', 'No pull requests or commits found.', 'none foundry commits, see pull request 12.'] as $rest) {
            self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** ".$rest."\n- **Status:** `ready to file`\n"), $rest);
        }
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineNamesAReferenceNeedsNoDate(): void
    {
        foreach (['fixed by symfony/ai#2889.', 'see https://github.com/symfony/ai/pull/2889', 'only in commit a1c443fe.', 'only in commit `1220483ebd24`.'] as $rest) {
            self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** ".$rest."\n- **Status:** `ready to file`\n"), $rest);
        }
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineNamesAWordOrNumberThatOnlyLooksLikeAReferenceIsAFinding(): void
    {
        foreach (['none in issue #, see facade.', 'none in 1234567 lines.', 'none, see deadbeef-cafe.', 'see effaced', 'n/a, see defaced', 'see ABCDEF1'] as $rest) {
            self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** ".$rest."\n- **Status:** `ready to file`\n"), $rest);
        }
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundWithAReferenceButWithoutADateIsAFinding(): void
    {
        foreach (['none found, searched https://github.com/symfony/ai/pulls?q=foo', 'none found among #2800 to #2900', 'none, defaced', 'No pull requests found among #2800 to #2900', 'Nothing found, searched https://github.com/symfony/ai/pulls?q=foo', '- none, see #12'] as $rest) {
            self::assertSame([self::NAMES_NOTHING], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** ".$rest."\n- **Status:** `ready to file`\n"), $rest);
        }
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineSaysNoneFoundWithAReferenceAndADatePasses(): void
    {
        foreach (['none found among #2800 to #2900 (checked 2026-11-07).', 'No pull requests found among #2800 to #2900 (checked 2026-11-07).'] as $rest) {
            self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** ".$rest."\n- **Status:** `ready to file`\n"), $rest);
        }
    }

    public function testAReadyToFileReportWhosePullRequestsAndCommitsLineHoldsOnlyADatedNotePasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-11-07 against main.\n- **Pull requests and commits:** searched on 2026-11-07, nothing touches this path.\n- **Status:** `ready to file`\n"));
    }

    public function testAReadyToFileReportWhoseCheckedLineStartsWithAnImpossibleDateIsAFinding(): void
    {
        self::assertSame(['upstream/a-bug/REPORT.md is ready to file but its "Checked" line does not start with a date as YYYY-MM-DD'], $this->findingsOf("- **Checked:** 2026-02-30 against main.\n".self::PULL_REQUESTS."- **Status:** `ready to file`\n"));
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
