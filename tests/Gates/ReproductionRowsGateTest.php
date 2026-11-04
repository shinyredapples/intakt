<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\ReproductionRowsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ReproductionRowsGate::class)]
#[UsesClass(FencedCodeBlocks::class)]
final class ReproductionRowsGateTest extends SandboxTestCase
{
    private const string SECTION = "## Found in the beta\n\nEach entry is a reproduction under `upstream/<slug>/`.\n\n| Probe | Status | Where | Upstream issue |\n|---|---|---|---|\n";

    public function testAReproductionWithoutARowFails(): void
    {
        $this->assertRejectedBy('reproductions', 'reproduction-without-row', 'upstream/messenger-lost-message/ has no row in "Found in the beta" of docs/highlights.md');
    }

    public function testAReproductionWithARowPasses(): void
    {
        $this->assertAcceptedBy('reproductions', 'reproduction-with-row');
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

        $run = $sandbox->check('--only=reproductions');

        self::assertTrue($run->failed('reproductions'), $run->output());
    }

    public function testARowInAnotherSectionDoesNotCount(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION."\n## Agent\n\n| Feature | Status | Where |\n|---|---|---|\n| A | done | `upstream/a-bug/` |\n");

        $run = $sandbox->check('--only=reproductions');

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

        $run = $sandbox->check('--only=reproductions');

        self::assertStringContainsString('upstream/a-bug/ has no row', $run->findingsOf('reproductions'));
    }

    public function testAnUntrackedReproductionNeedsARowToo(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION);
        $sandbox->write('upstream/a-bug/REPORT.md', "# Report\n");

        $run = $sandbox->check('--only=reproductions');

        self::assertStringContainsString('upstream/a-bug/ has no row', $run->findingsOf('reproductions'));
    }

    public function testATreeWithoutUpstreamFoldersPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");

        $run = $sandbox->check('--only=reproductions');

        self::assertTrue($run->passed('reproductions'), $run->output());
    }

    /**
     * @param list<string> $files
     */
    private function checkWith(array $files, string $rows): CheckRun
    {
        $sandbox = $this->emptySandbox();

        foreach ($files as $file) {
            $sandbox->write($file, "# Reproduction\n");
        }

        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::SECTION.$rows);

        return $sandbox->check('--only=reproductions');
    }
}
