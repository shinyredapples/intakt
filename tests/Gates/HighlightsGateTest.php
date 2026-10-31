<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\HighlightsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HighlightsGate::class)]
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

        $run = $sandbox->check('--only=highlights');

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

        $run = $sandbox->check('--only=highlights');

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('`src/Untracked.php`, which does not exist', $run->findingsOf('highlights'));
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

        $run = $sandbox->check('--only=highlights');

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testATableWithoutAWhereColumnOnlyChecksItsStatus(): void
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "| Probe | Status | Reproduction |\n|---|---|---|\n| P | done | `upstream/none/` |\n| Q | open | x |\n");

        $run = $sandbox->check('--only=highlights');

        self::assertStringContainsString('status "open"', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('does not exist', $run->findingsOf('highlights'));
    }

    public function testAMissingHighlightsFilePasses(): void
    {
        $run = $this->sandboxWithKernel()->check('--only=highlights');

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    private function checkRows(string $rows): CheckRun
    {
        $sandbox = $this->sandboxWithKernel();
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::HEADER.$rows);

        return $sandbox->check('--only=highlights');
    }

    private function sandboxWithKernel(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");

        return $sandbox;
    }
}
