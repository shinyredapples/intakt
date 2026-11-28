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

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('docs/draft.tmp: tracked although .gitignore excludes it', $run->findingsOf('ignored'));
    }

    public function testAnUnstagedEmptiedGitignoreThatHidesATrackedIgnoredFileFailsTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('build.log', "log\n");
        $sandbox->git('add', '--force', 'build.log');
        $sandbox->commitAll('chore: add a log');
        $sandbox->write('.gitignore', '');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('.gitignore: working-tree .gitignore differs from the index; stage or revert it', $run->findingsOf('ignored'));
    }

    public function testAnUnstagedDeletionOfANestedGitignoreFailsTheGate(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('docs/.gitignore', "*.tmp\n");
        $sandbox->commitAll('docs: ignore drafts');
        $sandbox->run('rm', 'docs/.gitignore');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('docs/.gitignore: working-tree .gitignore differs from the index', $run->findingsOf('ignored'));
    }

    public function testACleanTreeWithTheStagedRuleAndNothingIgnoredTrackedPassesTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('build.log', "log\n");
        $sandbox->write('notes.txt', "notes\n");
        $sandbox->commitAll('docs: add notes');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAConflictedIgnoredPathGivesExactlyOneFinding(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('build.log', "base\n");
        $sandbox->git('add', '--force', 'build.log');
        $sandbox->commitAll('chore: add a log');
        $sandbox->git('switch', '--quiet', '--create', 'other');
        $sandbox->write('build.log', "other\n");
        $sandbox->git('commit', '--quiet', '--all', '--message', 'chore: change the log');
        $sandbox->git('switch', '--quiet', 'change');
        $sandbox->write('build.log', "change\n");
        $sandbox->git('commit', '--quiet', '--all', '--message', 'chore: change the log here');
        $sandbox->git('merge', '--quiet', 'other');

        $stages = $sandbox->git('ls-files', '--stage', 'build.log')->output;
        $report = $sandbox->gateResult(new IgnoredFilesGate())->toFullJson();

        self::assertSame(3, substr_count($stages, 'build.log'), $stages);
        self::assertSame(1, substr_count($report, 'build.log: tracked although .gitignore excludes it'), $report);
    }

    public function testAPathThatIsNotValidUtf8IsQuotedTheWayGitQuotesIt(): void
    {
        $sandbox = $this->startedChange("*.tmp\n");
        $sandbox->write("report-\xFF.tmp", "report\n");
        $sandbox->git('add', '--force', "report-\xFF.tmp");
        $sandbox->commitAll('chore: add a report');

        $report = $sandbox->gateResult(new IgnoredFilesGate())->toFullJson();

        self::assertStringContainsString('"\"report-\\\\377.tmp\": tracked although .gitignore excludes it', $report);
    }

    public function testAPathWithANewlineIsQuotedTheWayGitQuotesIt(): void
    {
        $sandbox = $this->startedChange("*.tmp\n");
        $sandbox->write("two\nlines.tmp", "report\n");
        $sandbox->git('add', '--force', "two\nlines.tmp");
        $sandbox->commitAll('chore: add a report');

        $report = $sandbox->gateResult(new IgnoredFilesGate())->toFullJson();

        self::assertStringContainsString('"\"two\\\\nlines.tmp\": tracked although .gitignore excludes it', $report);
    }

    public function testAValidUtf8PathWithATabIsQuotedTheWayGitQuotesIt(): void
    {
        $sandbox = $this->startedChange("*.tmp\n");
        $sandbox->write("caf\u{e9}\tx.tmp", "report\n");
        $sandbox->git('add', '--force', "caf\u{e9}\tx.tmp");
        $sandbox->commitAll('chore: add a report');

        $report = $sandbox->gateResult(new IgnoredFilesGate())->toFullJson();

        self::assertStringContainsString('"\"caf\\\\303\\\\251\\\\tx.tmp\": tracked although .gitignore excludes it', $report);
    }

    public function testAPathWithAUnicodeNextLineIsQuotedTheWayGitQuotesIt(): void
    {
        $sandbox = $this->startedChange("*.tmp\n");
        $sandbox->write("r-\u{85}.tmp", "report\n");
        $sandbox->git('add', '--force', "r-\u{85}.tmp");
        $sandbox->commitAll('chore: add a report');

        $report = $sandbox->gateResult(new IgnoredFilesGate())->toFullJson();

        self::assertStringContainsString('"\"r-\\\\302\\\\205.tmp\": tracked although .gitignore excludes it', $report);
    }

    public function testAValidUtf8PathWithoutAControlCharacterStaysAsItIs(): void
    {
        $sandbox = $this->startedChange("*.tmp\n");
        $sandbox->write("caf\u{e9}.tmp", "report\n");
        $sandbox->git('add', '--force', "caf\u{e9}.tmp");
        $sandbox->commitAll('chore: add a report');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertStringContainsString("caf\u{e9}.tmp: tracked although .gitignore excludes it", $run->findingsOf('ignored'));
    }

    public function testAnUntrackedNestedGitignoreThatNegatesTheRuleStillFailsTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('logs/build.log', "log\n");
        $sandbox->git('add', '--force', 'logs/build.log');
        $sandbox->commitAll('chore: add a log');
        $sandbox->write('logs/.gitignore', "!build.log\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('logs/.gitignore: untracked .gitignore above tracked files; add or remove it', $run->findingsOf('ignored'));
    }

    public function testAnUntrackedGitignoreThatIgnoresItselfStillFailsTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('logs/build.log', "log\n");
        $sandbox->git('add', '--force', 'logs/build.log');
        $sandbox->commitAll('chore: add a log');
        $sandbox->write('logs/.gitignore', "!build.log\n.gitignore\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertStringContainsString('logs/.gitignore: untracked .gitignore above tracked files', $run->findingsOf('ignored'));
    }

    public function testAnUntrackedGitignoreWithNoTrackedFileBelowItPassesTheGate(): void
    {
        $sandbox = $this->startedChange("/vendor/\n");
        $sandbox->write('src/Report.php', "<?php\n");
        $sandbox->commitAll('feat: add a report');
        $sandbox->write('vendor/acme/tool/.gitignore', "!*\n");
        $sandbox->write('.idea/.gitignore', "/workspace.xml\n");

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnAssumeUnchangedGitignoreEmptiedInTheWorkingTreeFailsTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->write('build.log', "log\n");
        $sandbox->git('add', '--force', 'build.log');
        $sandbox->commitAll('chore: add a log');
        $sandbox->git('update-index', '--assume-unchanged', '.gitignore');
        $sandbox->write('.gitignore', '');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('.gitignore: marked assume-unchanged or skip-worktree; clear the mark', $run->findingsOf('ignored'));
    }

    public function testASkipWorktreeGitignoreFailsTheGate(): void
    {
        $sandbox = $this->startedChange("*.log\n");
        $sandbox->git('update-index', '--skip-worktree', '.gitignore');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertStringContainsString('.gitignore: marked assume-unchanged or skip-worktree', $run->findingsOf('ignored'));
    }

    public function testAnIgnoredGitignoreWithUnstagedChangesGivesExactlyOneFinding(): void
    {
        $sandbox = $this->startedChange("docs/.gitignore\n");
        $sandbox->write('docs/.gitignore', "*.log\n");
        $sandbox->git('add', '--force', 'docs/.gitignore');
        $sandbox->commitAll('docs: ignore logs');
        $sandbox->write('docs/.gitignore', "*.tmp\n");

        $result = $sandbox->gateResult(new IgnoredFilesGate());
        $findings = json_decode($result->toFullJson(), true, flags: \JSON_THROW_ON_ERROR)['findings'];

        self::assertSame(['docs/.gitignore: working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed; tracked although .gitignore excludes it; run git rm --cached on it, or add a negated rule to .gitignore'], $findings);
    }

    public function testAFileExcludedOnlyByARepositoryLocalExcludesFileDoesNotFailTheGate(): void
    {
        $sandbox = $this->startedChange("/.evidence/\n");
        $sandbox->write('notes.txt', "notes\n");
        $sandbox->commitAll('docs: add notes');
        $sandbox->write('.git/local-excludes', "notes.txt\n");
        $sandbox->git('config', 'core.excludesFile', '.git/local-excludes');

        $run = $sandbox->runGate(new IgnoredFilesGate());

        self::assertTrue($run->passed('ignored'), $run->output());
    }

    public function testAnIgnoredFileTrackedInAnEarlierCommitFailsWhenTheLatestCommitChangesAnotherFile(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitignore', "*.log\n");
        $sandbox->write('build.log', "log\n");
        $sandbox->git('add', '--force', '.gitignore', 'build.log');
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('README.md', "# Change\n");
        $sandbox->commitAll('docs: add a readme');

        $run = $sandbox->runGate(new IgnoredFilesGate(), 'main');

        self::assertTrue($run->failed('ignored'), $run->output());
        self::assertStringContainsString('build.log: tracked although .gitignore excludes it', $run->findingsOf('ignored'));
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
