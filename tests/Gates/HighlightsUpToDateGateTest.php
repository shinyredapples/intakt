<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\HighlightsRow;
use Intakt\Gates\HighlightsRows;
use Intakt\Gates\HighlightStatus;
use Intakt\Gates\HighlightsUpToDateGate;
use Intakt\Gates\MarkdownBlock;
use Intakt\Gates\MarkdownBlocks;
use Intakt\Gates\PathsOnBranch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightsUpToDateGate::class)]
#[CoversClass(HighlightsRows::class)]
#[CoversClass(HighlightsRow::class)]
#[CoversClass(PathsOnBranch::class)]
#[UsesClass(MarkdownBlock::class)]
#[UsesClass(MarkdownBlocks::class)]
#[UsesClass(HighlightStatus::class)]
final class HighlightsUpToDateGateTest extends SandboxTestCase
{
    private const string GATE = 'highlights-up-to-date';

    private const string BETA = "## Found in the beta\n\n| Probe | Status | Where | Upstream issue |\n|---|---|---|---|\n";

    private const string FEATURES = "## Tooling\n\n| Feature | How Intakt uses it | Status | Where |\n|---|---|---|---|\n";

    public function testAnUpstreamReproductionWithoutARowFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', self::BETA."| Other | in progress | T1 (#5), `upstream/other-bug/` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row in "Found in the beta"', $run->findingsOf(self::GATE));
    }

    public function testAnUpstreamReproductionWithARowPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/README.md', "x\n");
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', self::BETA."| Bug | in progress | T1 (#5), `upstream/some-bug/` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testARowNamingAFileInsideTheReproductionCountsForIt(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', self::BETA."| Bug | in progress | `./upstream/some-bug/REPORT.md` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAReproductionNamedOnlyOutsideFoundInTheBetaFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', self::FEATURES."| A | a | done | `upstream/some-bug/` |\n\n".self::BETA."| Bug | in progress | T1 (#5) | `upstream/some-bug/` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
    }

    public function testAnUntrackedUpstreamFolderNeedsNoRow(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.md', self::BETA);
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->write('upstream/scratch/notes.md', "x\n");

        $run = $sandbox->check('--only='.self::GATE);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAPlannedRowWhosePathsAllExistFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::FEATURES."| A | a | in progress | T1 (#5) |\n| B | b | planned | T2 (#6), `src/Kernel.php`, `src/` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('docs/highlights.md:6: status "planned", but every path in Where exists (`src/Kernel.php`, `src/`), update its status', $run->findingsOf(self::GATE));
    }

    public function testAReproductionRowInsideAnHtmlCommentDoesNotCount(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "## Found in the beta\n\n<!--\n| Probe | Status | Where |\n|---|---|---|\n| Bug | done | `upstream/some-bug/` |\n-->\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
    }

    public function testAReproductionRowAfterAClosedHtmlCommentCounts(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "<!-- one line -->\n".self::BETA."| Bug | in progress | `upstream/some-bug/` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAPlannedRowInsideAnHtmlCommentIsSkipped(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', "<!--\n".self::FEATURES."| B | b | planned | `src/Kernel.php` |\n-->\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAPlannedRowAfterACommentHoldingAFenceIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Built.php', "<?php\n");
        $sandbox->write('docs/highlights.md', "<!--\n```markdown\n-->\n\n".self::FEATURES."| Built | b | planned | `src/Built.php` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('docs/highlights.md:9: status "planned", but every path in Where exists (`src/Built.php`)', $run->findingsOf(self::GATE));
    }

    public function testAReproductionRowAfterACommentHoldingAFenceCounts(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "<!--\nWrite a row like this:\n```\n| Probe | Status | Where |\n-->\n\n".self::BETA."| Bug | in progress | `upstream/some-bug/` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAReproductionRowAfterAFenceHoldingACommentOpenerCounts(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "```markdown\n<!--\n```\n\n".self::BETA."| Bug | in progress | `upstream/some-bug/` | x |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAHeadingInsideAnHtmlCommentOpensNoSection(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "<!--\n## Found in the beta\n-->\n\n| Probe | Status | Where |\n|---|---|---|\n| Bug | done | `upstream/some-bug/` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
    }

    public function testAReproductionRowUnderASubheadingOfFoundInTheBetaCounts(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "# Highlights\n\n## Found in the beta\n\n### Platform\n\n| Probe | Status | Where |\n|---|---|---|\n| Bug | done | `upstream/some-bug/` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAHeadingOfTheSameLevelEndsFoundInTheBeta(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', "## Found in the beta\n\n### Platform\n\n## PHP 8.5 in use\n\n| Probe | Status | Where |\n|---|---|---|\n| Bug | done | `upstream/some-bug/` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
    }

    public function testAPlannedRowWithAMissingPathPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::FEATURES."| B | b | planned | T2 (#6), `src/Kernel.php`, `src/Later.php` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAPlannedRowWithoutPathsPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.md', self::FEATURES."| B | b | planned | T2 (#6) |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testADoneRowWhosePathsAllExistPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::FEATURES."| B | b | done | `src/Kernel.php` |\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testAPlannedRowInAFencedExampleIsSkipped(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', "```markdown\n".self::FEATURES."| B | b | planned | `src/Kernel.php` |\n```\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    public function testWithoutGitTheWorkingTreeCounts(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");
        $sandbox->write('docs/highlights.md', self::FEATURES."| B | b | planned | `src/Kernel.php` |\n");

        $run = $sandbox->check('--only='.self::GATE);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
        self::assertStringContainsString('status "planned", but every path in Where exists', $run->findingsOf(self::GATE));
    }

    public function testAnUpstreamReproductionWithoutAHighlightsFileFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/some-bug/REPORT.md', "x\n");

        $run = $this->checkCommitted($sandbox);

        self::assertTrue($run->failed(self::GATE), $run->output());
        self::assertStringContainsString('`upstream/some-bug/` has no row', $run->findingsOf(self::GATE));
    }

    public function testTheRepositoryItselfPasses(): void
    {
        $run = $this->checkCommitted($this->copyOfHighlightsAndUpstream());

        self::assertTrue($run->passed(self::GATE), $run->output());
    }

    private function checkCommitted(Sandbox $sandbox): CheckRun
    {
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        return $sandbox->check('--only='.self::GATE);
    }

    private function copyOfHighlightsAndUpstream(): Sandbox
    {
        $root = dirname(__DIR__, 2);
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/highlights.md', (string) file_get_contents($root.'/docs/highlights.md'));

        $reproductions = glob($root.'/upstream/*', GLOB_ONLYDIR);

        foreach (false === $reproductions ? [] : $reproductions as $reproduction) {
            $sandbox->write('upstream/'.basename($reproduction).'/REPORT.md', "x\n");
        }

        return $sandbox;
    }
}
