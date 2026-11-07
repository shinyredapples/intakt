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

    public function testAReadyToFileReportCheckedFourteenDaysAgoPasses(): void
    {
        self::assertSame([], $this->findingsOf("- **Checked:** 2026-10-24 against main.\n- **Status:** `ready to file`\n"));
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
