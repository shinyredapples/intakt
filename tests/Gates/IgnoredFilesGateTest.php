<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\IgnoredFilesGate;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(IgnoredFilesGate::class)]
#[UsesClass(Workspace::class)]
final class IgnoredFilesGateTest extends SandboxTestCase
{
    public function testAForceAddedFileThatGitignoreExcludesFailsTheGateNamingItsPath(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('.evidence/1796/built dark.png', "png\n");
        $sandbox->git('add', '--force', '.evidence/1796/built dark.png');
        $sandbox->commitAll('docs: add a screenshot');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('.evidence/1796/built dark.png: tracked although .gitignore excludes it', $run->findingsOf('ignored'));
    }

    public function testBinCheckRunsTheGateAndFailsOnAForceAddedIgnoredFile(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('build.log', "log\n");
        $sandbox->git('add', '--force', 'build.log');
        $sandbox->commitAll('chore: add a log');

        $run = $sandbox->check('--only=ignored');

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('build.log: tracked although .gitignore excludes it', $run->findingsOf('ignored'));
    }

    public function testATreeWithNothingIgnoredTrackedPassesTheGate(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('.evidence/shot.png', "png\n");
        $sandbox->write('src/Report.php', "<?php\n");
        $sandbox->commitAll('feat: add a report');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAFileKeptByANegatedGitignoreRulePassesTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n!keep.log\n");
        $sandbox->write('keep.log', "kept\n");
        $sandbox->commitAll('chore: keep one log');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAFileExcludedOnlyByTheLocalExcludeFileDoesNotFailTheGate(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('notes.txt', "notes\n");
        $sandbox->commitAll('docs: add notes');
        $sandbox->write('.git/info/exclude', "notes.txt\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnIgnoredFileInANestedGitignoreFailsTheGate(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('docs/.gitignore', "*.tmp\n");
        $sandbox->write('docs/draft.tmp', "draft\n");
        $sandbox->git('add', '--force', 'docs/draft.tmp');
        $sandbox->commitAll('docs: add a draft');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertStringContainsString('docs/draft.tmp: tracked although .gitignore excludes it', $run->findingsOf('ignored'));
    }

    public function testATreeWithoutGitPassesWithANote(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('README.md', "# Plain\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
        self::assertStringContainsString('skipped, no git history in this tree', $run->noteOf('ignored'));
    }

    public function testAGitCallThatFailsFailsTheGateWithGitsError(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('.git', "gitdir: missing\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('git ls-files failed:', $run->findingsOf('ignored'));
    }

    private function startedChange(string $gitignore): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitignore', $gitignore);
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }
}
