<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use DateTimeZone;
use Intakt\Gates\CheckedReports;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\ReproductionRowsGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\NativeClock;

#[CoversClass(ReproductionRowsGate::class)]
#[CoversClass(CheckedReports::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightFragments::class)]
#[UsesClass(Workspace::class)]
final class ReproductionRowsGateTest extends SandboxTestCase
{
    private const string SECTION = "## Found in the beta\n\nEach entry is a reproduction under `upstream/<slug>/`.\n\n| Probe | Status | Where | Upstream issue |\n|---|---|---|---|\n";

    public function testAReproductionWithoutARowFails(): void
    {
        $this->assertRejectedBy('reproductions', 'reproduction-without-row', 'upstream/messenger-lost-message/ has no row in "Found in the beta" of docs/highlights.md');
    }

    public function testAReproductionWithARowPasses(): void
    {
        $run = $this->sandboxWith('reproduction-with-row')->runGate($this->gate());

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testEveryReproductionWithoutARowIsNamed(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md', 'upstream/b-bug/REPORT.md'], "| A | done | T1 (#5) | |\n");

        self::assertStringContainsString('upstream/a-bug/', $run->findingsOf('reproductions'));
        self::assertStringContainsString('upstream/b-bug/', $run->findingsOf('reproductions'));
    }

    public function testARowNamingTheFolderWithoutATrailingSlashPasses(): void
    {
        $run = $this->checkWith(['upstream/a-bug/reproduce.php'], "| A | done | T1 (#5), `upstream/a-bug` | |\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testARowNamingALongerFolderDoesNotCountForAShorterOne(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| A | done | `upstream/a-bug-two/` | |\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testARowNamingAnotherPathEndingInTheFolderDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| A | done | `docs/upstream/a-bug/` | |\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testARowOfATableWithoutOuterPipesPasses(): void
    {
        $run = $this->checkWithSection(['upstream/a-bug/REPORT.md'], "## Found in the beta\n\nProbe | Where\n--- | ---\nA | `upstream/a-bug/`\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAPipePrefixedLineOutsideATableFails(): void
    {
        $run = $this->checkWithSection(['upstream/a-bug/REPORT.md'], "## Found in the beta\n\n| See `upstream/a-bug/` for an example.\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testALineAfterTheEndOfATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "\n| See `upstream/a-bug/` for an example.\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testProseUnderAHeadingRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n### Notes\nSee `upstream/a-bug/` for details.\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAListItemRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n- pending: `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testABlockquoteRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n> see `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAPipeLineAfterALineThatEndedTheTableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n### Notes\n| See `upstream/a-bug/` for details.\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAHeadingWithAPipeRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n### Notes | `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAListItemWithAPipeRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n- pending | `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAnOrderedListItemWithAPipeRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n1) pending | `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testABlockquoteWithAPipeRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n  > see | `upstream/a-bug/`\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAnHtmlCommentWithAPipeRightAfterATableDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | elsewhere | |\n<!-- pending | `upstream/a-bug/` -->\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testACellPastTheHeaderDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| A | done | T1 (#5) | | `upstream/a-bug/` |\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testATableIndentedFourSpacesDoesNotCount(): void
    {
        $run = $this->checkWithSection(['upstream/a-bug/REPORT.md'], "## Found in the beta\n\n    | Probe | Where |\n    |---|---|\n    | A | `upstream/a-bug/` |\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testATableIndentedThreeSpacesCounts(): void
    {
        $run = $this->checkWithSection(['upstream/a-bug/REPORT.md'], "## Found in the beta\n\n   | Probe | Where |\n   |---|---|\n   | A | `upstream/a-bug/` |\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testARowWithFewerCellsThanTheHeaderStillCounts(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| A | `upstream/a-bug/` |\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testARowAfterARowWithAnEscapedPipeStillCounts(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "| Other | done | `a\\|b` | |\n| A | done | `upstream/a-bug/` | |\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testANestedFolderOnlyNeedsTheRowOfItsReproduction(): void
    {
        $run = $this->checkWith(['upstream/a-bug/tests/ReproductionTest.php'], "| A | done | `upstream/a-bug/` | |\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAFileDirectlyUnderUpstreamNeedsNoRow(): void
    {
        $run = $this->checkWith(['upstream/README.md'], '');

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAFolderNamedOnlyInTheProseOfTheSectionFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");
        $sandbox->write('docs/highlights.md', "# Highlights\n\n## Found in the beta\n\nSee `upstream/a-bug/`.\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testARowInAnotherSectionDoesNotCount(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION."\n## Agent\n\n| Feature | Status | Where |\n|---|---|---|\n| A | done | `upstream/a-bug/` |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testARowInsideAFencedCodeBlockDoesNotCount(): void
    {
        $run = $this->checkWith(['upstream/a-bug/REPORT.md'], "```\n| A | done | `upstream/a-bug/` | |\n```\n");

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testAReproductionWithoutAHighlightsFileFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('upstream/a-bug/ has no row', $run->findingsOf('reproductions'));
    }

    public function testAnUntrackedReproductionNeedsARowToo(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION);
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('upstream/a-bug/ has no row', $run->findingsOf('reproductions'));
    }

    public function testATreeWithoutUpstreamFoldersPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAReadyToFileReportWithoutACheckedLineFails(): void
    {
        $run = $this->checkReport("- **Decision:** `ready to file`.\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileReportCheckedThreeDaysAgoPasses(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(3)." against main.\n- **Decision:** `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAReadyToFileReportCheckedTwentyDaysAgoFails(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(20)." against main.\n- **Decision:** `ready to file`.\n");

        self::assertStringContainsString('older than 14 days', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileReportCheckedInTheFutureFails(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(-3)." against main.\n- **Decision:** `ready to file`.\n");

        self::assertStringContainsString('dated in the future', $run->findingsOf('reproductions'));
    }

    public function testACheckedLineWithoutAReadableDateFails(): void
    {
        $run = $this->checkReport("- **Checked:** yesterday.\n- **Decision:** `ready to file`.\n");

        self::assertStringContainsString('does not start with a date', $run->findingsOf('reproductions'));
    }

    public function testAReportThatIsNotReadyToFileNeedsNoCheckedLine(): void
    {
        $run = $this->checkReport("- **Decision:** `already reported`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAReportRetiredAsNotFiledNeedsNoCheckedLine(): void
    {
        $run = $this->checkReport("- **Decision:** `ready to file`.\n- **Status:** `not filed`, although it was `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAReadyToFileStatusInsideAFencedCodeBlockDoesNotCount(): void
    {
        $run = $this->checkReport("```\n- **Decision:** `ready to file`.\n```\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testANotFiledStatusInALaterSectionDoesNotRetireAReadyReport(): void
    {
        $run = $this->checkReport("- **Status:** `ready to file`\n\n## Fix experiment\n\n- **Status:** `not filed`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusOutsideTheUpstreamStatusSectionFails(): void
    {
        $run = $this->checkReport("- **Status:** `not filed`\n\n## Fix experiment\n\n- **Status:** `ready to file`\n");

        self::assertStringContainsString('write the status as a list item in the Upstream status section', $run->findingsOf('reproductions'));
    }

    public function testACheckedLineOutsideTheUpstreamStatusSectionDoesNotCount(): void
    {
        $run = $this->checkReport("- **Decision:** `ready to file`.\n\n## Notes\n\n- **Checked:** ".$this->daysAgo(3)." against main.\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testASubheadingInsideTheUpstreamStatusSectionKeepsItsLines(): void
    {
        $run = $this->checkReport("### Check\n\n- **Checked:** ".$this->daysAgo(3)." against main.\n- **Decision:** `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testANotFiledStatusUnderALaterHeadingIndentedOneSpaceDoesNotRetireAReadyReport(): void
    {
        $run = $this->checkReport("- **Status:** `ready to file`\n\n ## Fix experiment\n\n- **Status:** `not filed`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testANotFiledStatusUnderALaterHeadingIndentedThreeSpacesDoesNotRetireAReadyReport(): void
    {
        $run = $this->checkReport("- **Status:** `ready to file`\n\n   ## Fix experiment\n\n- **Status:** `not filed`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAHeadingIndentedFourSpacesIsCodeAndKeepsTheSectionOpen(): void
    {
        $run = $this->checkReport("- **Status:** `ready to file`\n\n    ## Fix experiment\n\n- **Status:** `not filed`\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAnUpstreamStatusHeadingIndentedThreeSpacesOpensTheSection(): void
    {
        $run = $this->checkReportText("# Report\n\n   ## Upstream status\n\n- **Status:** `ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testACheckedDateFollowedByAColonIsRead(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(3).": main abc1234, 7.3.5.\n- **Status:** `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testACheckedDateFollowedByAPeriodIsRead(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(3).".\n- **Status:** `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testACheckedLineWithADateGluedToMoreDigitsFails(): void
    {
        $run = $this->checkReport('- **Checked:** '.$this->daysAgo(3)."1 against main.\n- **Status:** `ready to file`.\n");

        self::assertStringContainsString('does not start with a date', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileReportWithoutAnUpstreamStatusHeadingFails(): void
    {
        $run = $this->checkReportText("# Report\n\n## Upstream status (2026-11)\n\n- **Checked:** ".$this->daysAgo(3)." against main.\n- **Status:** `ready to file`.\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "## Upstream status" section', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileReportUnderAnUpstreamStatusHeadingInAnotherCaseFails(): void
    {
        $run = $this->checkReportText("# Report\n\n## Upstream Status\n\n- **Status:** `ready to file`.\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "## Upstream status" section', $run->findingsOf('reproductions'));
    }

    public function testAReportWithoutAnUpstreamStatusHeadingThatIsNotReadyToFilePasses(): void
    {
        $run = $this->checkReportText("# Report\n\n## Status\n\n- **Status:** `already reported`, it was never `ready to file`.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAReadyToFileStatusWithoutBackticksFails(): void
    {
        $run = $this->checkReport("- **Status:** ready to file\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusInAStarListItemFails(): void
    {
        $run = $this->checkReport("* **Status:** `ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusInAnOrderedListItemFails(): void
    {
        $run = $this->checkReport("1. **Status:** `ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusWithoutAListMarkerFails(): void
    {
        $run = $this->checkReport("**Status:** `ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusInCapitalsFails(): void
    {
        $run = $this->checkReport("- **Status:** `Ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusWithTextAfterItInsideTheBackticksFails(): void
    {
        $run = $this->checkReport("- **Status:** `ready to file as a new issue`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testAReadyToFileStatusWithTheColonAfterTheBoldLabelFails(): void
    {
        $run = $this->checkReport("- **Status**: `ready to file`\n");

        self::assertStringContainsString('upstream/a-bug/REPORT.md is ready to file but has no "Checked" line', $run->findingsOf('reproductions'));
    }

    public function testANotFiledStatusInAStarListItemRetiresAReadyReport(): void
    {
        $run = $this->checkReport("- **Decision:** `ready to file`.\n* **Status:** not filed, the owner declined it.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    public function testAStatusThatOnlyLaterMentionsReadyToFileNeedsNoCheckedLine(): void
    {
        $run = $this->checkReport("- **Status:** `recheck needed before filing`, it was ready to file.\n");

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    private function gate(): ReproductionRowsGate
    {
        return new ReproductionRowsGate(new FencedCodeBlocks(), new CheckedReports(new FencedCodeBlocks(), new NativeClock()), new HighlightFragments());
    }

    private function daysAgo(int $days): string
    {
        return new NativeClock()->now()->setTimezone(new DateTimeZone('UTC'))->modify('-'.$days.' days')->format('Y-m-d');
    }

    private function checkReport(string $status): CheckRun
    {
        return $this->checkReportText("# Report\n\n## Upstream status\n\n".$status);
    }

    private function checkReportText(string $report): CheckRun
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', $report);
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION."| A | done | `upstream/a-bug/` | |\n");

        return $sandbox->runGate($this->gate());
    }

    /**
     * @param list<string> $files
     */
    private function checkWith(array $files, string $rows): CheckRun
    {
        return $this->checkWithSection($files, self::SECTION.$rows);
    }

    /**
     * @param list<string> $files
     */
    private function checkWithSection(array $files, string $section): CheckRun
    {
        $sandbox = $this->emptySandbox();

        foreach ($files as $file) {
            $sandbox->write($file, "# Reproduction\n");
        }

        $sandbox->write('docs/highlights.md', "# Highlights\n\n".$section);

        return $sandbox->runGate($this->gate());
    }
}
