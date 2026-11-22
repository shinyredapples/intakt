<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightsGate;
use Intakt\Gates\HighlightStatus;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightsGate::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightStatus::class)]
#[UsesClass(HighlightFragments::class)]
#[UsesClass(Workspace::class)]
final class HighlightsGateTest extends SandboxTestCase
{
    private const string HEADER = "| Feature | How Intakt uses it | Status | Where |\n|---|---|---|---|\n";

    public function testRowsWithAKnownStatusAndAnExistingPathPass(): void
    {
        $run = $this->checkRows("| A | a | planned | T1 (#5) |\n| B | b | in progress | T2 (#6), `src/Kernel.php` |\n| C | c | done | `src/` |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAStatusOutsideTheThreeKnownOnesFails(): void
    {
        $run = $this->checkRows("| A | a | started | T1 (#5) |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md:5: status "started"', $run->findingsOf('highlights'));
    }

    public function testAPullRequestNumberInWhereFails(): void
    {
        $run = $this->checkRows("| A | a | done | T1 (#5), PR #77 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('cites a pull request ("PR #77")', $run->findingsOf('highlights'));
    }

    public function testAPullRequestNumberAfterPunctuationFails(): void
    {
        $run = $this->checkRows("| A | a | done | T1 (#5), PR: #77 |\n| B | b | done | PR (#78) |\n| C | c | done | pull request: 79 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('cites a pull request ("PR: #77")', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("PR (#78")', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("pull request: 79")', $run->findingsOf('highlights'));
    }

    public function testAPullRequestNumberRightAfterTheLabelFails(): void
    {
        $run = $this->checkRows("| A | a | done | PR12 |\n| B | b | done | pr13 |\n| C | c | done | PRs14 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('cites a pull request ("PR12")', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("pr13")', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("PRs14")', $run->findingsOf('highlights'));
    }

    public function testAWordBetweenAPullRequestLabelAndANumberIsAllowed(): void
    {
        $run = $this->checkRows("| A | a | done | pull the lever, see #77 |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAnIssueNumberInWhereIsAllowed(): void
    {
        $run = $this->checkRows("| A | a | done | T1 (#77, part #78) |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testABacktickedPathThatDoesNotExistFails(): void
    {
        $run = $this->checkRows("| A | a | done | T1 (#5), `src/Missing.php` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`src/Missing.php`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testAMissingRootFileWithAnyExtensionFails(): void
    {
        $run = $this->checkRows("| A | a | done | `missing.lock`, `missing.txt` |\n");

        self::assertStringContainsString('`missing.lock`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringContainsString('`missing.txt`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testAMissingPathWithASpaceFails(): void
    {
        $run = $this->checkRows("| A | a | done | `src/definitely missing.php`, `definitely missing.txt` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`src/definitely missing.php`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringContainsString('`definitely missing.txt`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testAnIgnoredFileCountsAsMissingFromTheBranch(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('.gitignore', "var/\n");
        $sandbox->write('var/cache.txt', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `var/cache.txt`, `src/Kernel.php`, `src/` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`var/cache.txt`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('Kernel.php', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('`src/`', $run->findingsOf('highlights'));
    }

    public function testAnUntrackedFileCountsAsMissingFromTheBranch(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->write('src/Untracked.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `src/Untracked.php` |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`src/Untracked.php`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testATrackedPathWithALeadingDotSlashPasses(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `./src/Kernel.php` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAPathWithAParentSegmentFails(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `src/../src/Kernel.php` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('`src/../src/Kernel.php`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testATableInsideAFencedCodeBlockIsNotLinted(): void
    {
        $example = "| Status | Where |\n|---|---|\n| started | PR #77 |\n";
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "```markdown\n".$example."```\n\n~~~\n".$example."~~~\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableAfterAClosedFenceIsLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "```\n| Status | Where |\n|---|---|\n| started | x |\n```\n\n| Status | Where |\n|---|---|\n| open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('docs/highlights.md:9: status "open"', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('started', $run->findingsOf('highlights'));
    }

    public function testAShorterFenceInsideALongerOneDoesNotCloseIt(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "````markdown\n```\n| Status | Where |\n|---|---|\n| invalid | PR12 |\n```\n````\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableAfterALongerFenceWithAnInnerFenceIsLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "````markdown\n```php\necho 1;\n```\n````\n\n| Status | Where |\n|---|---|\n| open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('docs/highlights.md:9: status "open"', $run->findingsOf('highlights'));
    }

    public function testATableWithoutOuterPipesIsCheckedByEveryRule(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "Feature | Status | Where\n---|---|---\nA | invalid | PR #77, `src/Missing.php`\n");

        $run = $sandbox->check('--only=highlights');

        self::assertStringContainsString('docs/highlights.md:3: status "invalid"', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("PR #77")', $run->findingsOf('highlights'));
        self::assertStringContainsString('`src/Missing.php`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testAPipeOutsideATableIsNotLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "Status | Where are we\nsome text | more text\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableWithoutAWhereColumnOnlyChecksItsStatus(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "| Probe | Status | Reproduction |\n|---|---|---|\n| P | done | `upstream/none/` |\n| Q | open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertStringContainsString('status "open"', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('does not exist', $run->findingsOf('highlights'));
    }

    public function testAFenceLineWithTrailingTextDoesNotCloseTheBlock(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "```markdown\n```not-a-close\n| Status | Where |\n|---|---|\n| started | PR #77 |\n```\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableAfterAnIndentedClosingFenceWithTrailingSpacesIsLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "```\n| Status | Where |\n|---|---|\n| started | x |\n   ```  \t\n\n| Status | Where |\n|---|---|\n| open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md:9: status "open"', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('started', $run->findingsOf('highlights'));
    }

    public function testAFenceIndentedThreeSpacesOpensABlock(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "   ```markdown\n| Status | Where |\n|---|---|\n| started | PR #77 |\n```\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableAfterAFenceIndentedFourSpacesIsLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "    ```\n\n| Status | Where |\n|---|---|\n| open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md:5: status "open"', $run->findingsOf('highlights'));
    }

    public function testATableAfterAnInlineBacktickRunIsLinted(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "```inline``` code here\n\n| Status | Where |\n|---|---|\n| open | x |\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('docs/highlights.md:5: status "open"', $run->findingsOf('highlights'));
    }

    public function testATildeFenceWithBackticksInItsInfoStringOpensABlock(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "~~~ a`b\n| Status | Where |\n|---|---|\n| started | PR #77 |\n~~~\n");

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testABacktickedTrackedPathThatLooksLikeAPullRequestPasses(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('upstream/pr1-repro/README.md', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | T1 (#5), `upstream/pr1-repro/` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testABacktickedUntrackedPathThatLooksLikeAPullRequestStillFails(): void
    {
        $run = $this->checkRows("| A | a | done | `upstream/pr1-repro/` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`upstream/pr1-repro/`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('cites a pull request', $run->findingsOf('highlights'));
    }

    public function testATrackedPathInADoubleBacktickSpanHoldingABacktickPasses(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('upstream/`pr1-repro/README.md', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | ``upstream/`pr1-repro/`` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAnUntrackedPathInADoubleBacktickSpanIsCheckedWhole(): void
    {
        $run = $this->checkRows("| A | a | done | ``upstream/`pr1-repro/`` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`upstream/`pr1-repro/`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('cites a pull request', $run->findingsOf('highlights'));
    }

    public function testASpaceOnBothSidesOfACodeSpanIsNotPartOfThePath(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('upstream/pr1-repro/README.md', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `` upstream/pr1-repro/ `` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAPullRequestNumberAfterAnUnmatchedBacktickRunIsStillRefused(): void
    {
        $run = $this->checkRows("| A | a | done | ``a` PR #12 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('cites a pull request ("PR #12")', $run->findingsOf('highlights'));
    }

    public function testAPullRequestNumberBetweenEscapedBackticksIsRefused(): void
    {
        $run = $this->checkRows("| A | a | done | \\`PR12\\` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('cites a pull request ("PR12")', $run->findingsOf('highlights'));
    }

    public function testACodeSpanAfterAnEscapedBackslashHidesAPullRequestNumber(): void
    {
        $run = $this->checkRows("| A | a | done | \\\\`PR12` |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testACodeSpanRightAfterAnEscapedBacktickIsCheckedAsAPath(): void
    {
        $run = $this->checkRows("| A | a | done | \\``upstream/pr1-repro/` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`upstream/pr1-repro/`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('cites a pull request', $run->findingsOf('highlights'));
    }

    public function testAMissingRootFileWithPunctuationInItsNameFails(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `missing@2.php`, `c++.md` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`missing@2.php`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringContainsString('`c++.md`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testATrackedRootFileWithPunctuationInItsNamePasses(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('c++.md', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `c++.md` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATrackedPathWithAnEscapedTablePipePasses(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('a|b.md', "x\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| A | a | done | `a\\|b.md` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAnUntrackedPathWithAnEscapedTablePipeFailsUnderItsDecodedName(): void
    {
        $run = $this->checkRows("| A | a | done | `missing\\|b.md` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`missing|b.md`, which does not exist', $run->findingsOf('highlights'));
    }

    public function testABareBacktickedNameWithoutAnExtensionIsNotAPath(): void
    {
        $run = $this->checkRows("| A | a | done | `HighlightStatus`, `c++`, `a<b>.md` |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAWhereCellTooLongToMatchFailsTheGate(): void
    {
        $run = $this->checkRows('| A | a | done | `'.str_repeat('a', 1_100_000)." PR12 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('Where cannot be checked', $run->findingsOf('highlights'));
    }

    public function testALongWhereCellWithinTheLimitIsStillChecked(): void
    {
        $run = $this->checkRows('| A | a | done | `'.str_repeat('a', 20_000).".md` PR12 |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringNotContainsString('cannot be checked', $run->findingsOf('highlights'));
        self::assertStringContainsString('cites a pull request ("PR12")', $run->findingsOf('highlights'));
        self::assertStringContainsString('Where names `aaaa', $run->findingsOf('highlights'));
    }

    public function testAMissingHighlightsFilePasses(): void
    {
        $run = $this->sandboxWithKernel()->runGate($this->gate());

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    private function checkRows(string $rows): CheckRun
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::HEADER.$rows);

        return $sandbox->runGate($this->gate());
    }

    private function sandboxWithKernel(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");

        return $sandbox;
    }

    private function gate(): HighlightsGate
    {
        return new HighlightsGate(new FencedCodeBlocks(), new HighlightFragments());
    }
}
