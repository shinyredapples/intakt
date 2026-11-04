<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\HighlightsGate;
use Intakt\Gates\HighlightStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightsGate::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(HighlightStatus::class)]
final class HighlightsPlannedRowsTest extends SandboxTestCase
{
    private const string HEADER = "| Feature | How Intakt uses it | Status | Where |\n|---|---|---|---|\n";

    public function testAPlannedRowWhosePathsAllExistFails(): void
    {
        $run = $this->checkRows("| Clock | a | planned | T1 (#5), `src/Kernel.php`, `src/` |\n");

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString(
            'docs/highlights.md:5: row "Clock" is planned, but every path its Where names exists: update its status',
            $run->findingsOf('highlights'),
        );
    }

    public function testAPlannedRowWithAMissingPathGetsNoStatusFinding(): void
    {
        $run = $this->checkRows("| Clock | a | planned | T1 (#5), `src/Kernel.php`, `src/Clock.php` |\n");

        self::assertStringContainsString('`src/Clock.php`, which does not exist', $run->findingsOf('highlights'));
        self::assertStringNotContainsString('is planned', $run->findingsOf('highlights'));
    }

    public function testAPlannedRowWithoutAPathPasses(): void
    {
        $run = $this->checkRows("| Clock | a | planned | T1 (#5), `Clock` |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testARowInProgressWhosePathsAllExistPasses(): void
    {
        $run = $this->checkRows("| Clock | a | in progress | T1 (#5), `src/Kernel.php` |\n| Lock | b | done | `./src/Kernel.php` |\n");

        self::assertTrue($run->passed('highlights'), $run->output());
    }

    public function testAPlannedRowNamingATrackedPathWithALeadingDotSlashFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| Clock | a | planned | `./src/Kernel.php` |\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        $run = $sandbox->check('--only=highlights');

        self::assertTrue($run->failed('highlights'), $run->output());
        self::assertStringContainsString('row "Clock" is planned', $run->findingsOf('highlights'));
    }

    public function testAPlannedRowNamingAnUntrackedPathGetsNoStatusFinding(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->write('src/Untracked.php', "<?php\n");
        $sandbox->write('docs/highlights.md', self::HEADER."| Clock | a | planned | `src/Untracked.php` |\n");

        $run = $sandbox->check('--only=highlights');

        self::assertStringNotContainsString('is planned', $run->findingsOf('highlights'));
    }

    private function checkRows(string $rows): CheckRun
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Kernel.php', "<?php\n");
        $sandbox->write('docs/highlights.md', "# Highlights\n\n".self::HEADER.$rows);

        return $sandbox->check('--only=highlights');
    }
}
