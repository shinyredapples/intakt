<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does, with a scripted CLI in place of Claude Code.
#[CoversNothing]
final class BuildScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/build.sh';

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';
    private string $bin = '';
    private string $prompt = '';

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        $this->work = $this->history->folder.'/job';
        $this->bin = $this->history->folder.'/bin';
        $this->prompt = $this->history->folder.'/prompt.md';
        new Filesystem()->mkdir([$this->output, $this->work, $this->bin]);
        file_put_contents($this->prompt, "Build the task.\n");
    }

    protected function tearDown(): void
    {
        // A scripted CLI may take the output's permissions away, which would stop the removal.
        new Filesystem()->chmod($this->output, 0o700);
        $this->history->remove();
    }

    public function testACliThatCommitsAndWritesThePullRequestTextLeavesABundleOfTheBranchAndTheText(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            printf 'feat: build the task\n\n## Summary\n' > PR.md
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("feat: build the task\n\n## Summary\n", $this->outputFile('pull-request.md'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testTheCliGetsThePromptOnItsInputAndTheModelToRun(): void
    {
        $this->givenTheCli('printf "%s\n" "$@" > "$RECORD/arguments"; cat > "$RECORD/input"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("Build the task.\n", (string) file_get_contents($this->history->folder.'/input'));
        self::assertSame(
            ['-p', '--model', 'claude-opus-5-5', '--max-turns', '7', '--output-format', 'json', '--dangerously-skip-permissions'],
            explode("\n", trim((string) file_get_contents($this->history->folder.'/arguments'))),
        );
    }

    public function testASessionCutAtItsAllowanceAfterCommittingHandsBackABundleOfItsCommits(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            sleep 30
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 3);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
        self::assertStringContainsString("claude cut after 3 seconds, its commits are kept\n", $this->outputFile('build.log'));
    }

    public function testASessionThatEndsByItsTurnLimitKeepsItsCommitsAndIsNotLoggedAsCut(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            echo '{"subtype":"error_max_turns"}'
            exit 1
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
        self::assertStringNotContainsString('cut after', $this->outputFile('build.log'));
    }

    public function testASessionThatEndsBeforeItsAllowanceLeavesNoWatchdogBehind(): void
    {
        $this->givenTheCli('exit 0');
        $this->givenSleepRecordsItsProcess();

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 31);

        self::assertSame(0, $run->getExitCode());
        $recorded = file($this->history->folder.'/sleeps', FILE_IGNORE_NEW_LINES);
        $sleeps = array_map(intval(...), false === $recorded ? [] : $recorded);
        self::assertNotSame([], $sleeps);
        self::assertSame([], array_values(array_filter($sleeps, $this->isRunning(...))));
    }

    public function testTheCliWorksInAFreshCloneOnTheTasksBranchWithOnlyTheBuildClonesBranches(): void
    {
        $head = $this->history->commit('Earlier build work');
        file_put_contents($this->history->work.'/left-in-the-build-clone.txt', 'not committed');
        $this->givenTheCli('{ git rev-parse HEAD; git symbolic-ref --short HEAD; git for-each-ref --format="%(refname)"; ls; } > "$RECORD/seen"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(
            $head."\ntask\nrefs/heads/main\nrefs/heads/task\n",
            (string) file_get_contents($this->history->folder.'/seen'),
        );
    }

    public function testABuildThatMakesNoCommitLeavesNoBundle(): void
    {
        $this->givenTheCli("printf 'docs: nothing\\n' > PR.md");

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertFileExists($this->output.'/pull-request.md');
    }

    public function testABundleTheCliWritesIntoTheOutputIsNotHandedBack(): void
    {
        $this->givenTheCli("echo planted > \"\$INTAKT_JOB_OUTPUT/handback.bundle\"; printf 'docs: nothing\\n' > PR.md");

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testAPullRequestTextTheCliWritesIntoTheOutputIsNotHandedBack(): void
    {
        $this->givenTheCli('echo planted > "$INTAKT_JOB_OUTPUT/pull-request.md"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/pull-request.md');
    }

    public function testFilesTheCliPlantsAreNotHandedBackWhenItTakesAwayTheOutputsWritePermission(): void
    {
        $this->givenTheCli(<<<'SH'
            echo planted > "$INTAKT_JOB_OUTPUT/handback.bundle"
            echo planted > "$INTAKT_JOB_OUTPUT/pull-request.md"
            chmod 500 "$INTAKT_JOB_OUTPUT"
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertFileDoesNotExist($this->output.'/pull-request.md');
    }

    public function testAFolderTheCliPlantsWithoutPermissionsIsNotHandedBack(): void
    {
        $this->givenTheCli(<<<'SH'
            mkdir -p "$INTAKT_JOB_OUTPUT/handback.bundle/locked"
            chmod 000 "$INTAKT_JOB_OUTPUT/handback.bundle/locked"
            chmod 500 "$INTAKT_JOB_OUTPUT/handback.bundle"
            printf 'docs: nothing\n' > PR.md
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testAnOutputThatCannotBeClearedEndsWithTheCodeThatHandsNothingBack(): void
    {
        // A remove that does nothing stands in for a removal the job cannot do.
        file_put_contents($this->bin.'/rm', "#!/bin/sh\nexit 0\n");
        chmod($this->bin.'/rm', 0o755);
        $this->givenTheCli('echo planted > "$INTAKT_JOB_OUTPUT/pull-request.md"');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(RunBuildJob::OUTPUT_NOT_CLEARED, $run->getExitCode());
        self::assertStringContainsString('the output folder could not be cleared', $this->outputFile('build.log'));
    }

    public function testTheScriptEndsWithSuccessWhateverTheClisStatusAndLogsThatStatus(): void
    {
        $this->givenTheCli('exit '.RunBuildJob::OUTPUT_NOT_CLEARED);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode());
        self::assertStringContainsString('claude exited with '.RunBuildJob::OUTPUT_NOT_CLEARED."\n", $this->outputFile('build.log'));
    }

    public function testAProcessTheCliLeavesRunningCannotHandBackABundleAfterTheSession(): void
    {
        // Opening the pipe waits until git reads its global settings after the session, so without an end to that process the bundle would land after the cleanup.
        $this->givenTheCli(<<<'SH'
            echo 'docs: nothing' > PR.md
            mkfifo "$TMPDIR/settings"
            git config --global include.path "$TMPDIR/settings"
            (
                {
                    echo planted > "$INTAKT_JOB_OUTPUT/handback.bundle"
                    rm "$TMPDIR/settings"
                    touch "$TMPDIR/settings"
                } > "$TMPDIR/settings"
            ) > /dev/null 2>&1 &
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("docs: nothing\n", $this->outputFile('pull-request.md'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testWhatAFailedBundleLeftIsNotHandedBack(): void
    {
        $this->givenGitLeavesAPartialBundleAndFails();
        $this->givenTheCli('echo built > built.txt && git add built.txt && git commit --quiet -m "feat: build"');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testAFailedBundleThatCannotBeRemovedEndsWithTheCodeThatHandsNothingBack(): void
    {
        $this->givenGitLeavesAPartialBundleAndFails();
        // A remove that does nothing stands in for a removal the job cannot do.
        file_put_contents($this->bin.'/rm', "#!/bin/sh\nexit 0\n");
        chmod($this->bin.'/rm', 0o755);
        $this->givenTheCli('echo built > built.txt && git add built.txt && git commit --quiet -m "feat: build"');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(RunBuildJob::OUTPUT_NOT_CLEARED, $run->getExitCode());
        self::assertStringContainsString('the output folder could not be cleared', $this->outputFile('build.log'));
    }

    public function testAPullRequestTextThatIsALinkIsNotHandedBack(): void
    {
        $this->givenTheCli('ln -s "$RECORD/prompt.md" PR.md');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/pull-request.md');
    }

    public function testTheClisOutputGoesToTheLog(): void
    {
        $this->givenTheCli('echo "{\"type\":\"result\"}"; echo "warning" >&2');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertStringContainsString("{\"type\":\"result\"}\nwarning\n", $this->outputFile('build.log'));
    }

    public function testABranchTheBuildCloneDoesNotHoldRunsNoCli(): void
    {
        $this->givenTheCli('touch "$RECORD/ran"');

        $run = $this->whenTheBuildRuns('another-task', 'claude-sonnet-5-5');

        self::assertNotSame(0, $run->getExitCode());
        self::assertFileDoesNotExist($this->history->folder.'/ran');
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    public function testABranchNameThatReadsAsAnOptionRunsNoCli(): void
    {
        $this->givenTheCli('touch "$RECORD/ran"');

        $run = $this->whenTheBuildRuns('--orphan', 'claude-sonnet-5-5');

        self::assertNotSame(0, $run->getExitCode());
        self::assertFileDoesNotExist($this->history->folder.'/ran');
    }

    private function givenTheCli(string $script): void
    {
        file_put_contents($this->bin.'/claude', "#!/bin/sh\n".$script."\n");
        chmod($this->bin.'/claude', 0o755);
    }

    // Each sleep reports its own process id, because the CI image has no procps to look it up with.
    private function givenSleepRecordsItsProcess(): void
    {
        $sleep = new ExecutableFinder()->find('sleep');
        self::assertNotNull($sleep);
        file_put_contents($this->bin.'/sleep', "#!/bin/sh\necho \$\$ >> \"\$RECORD/sleeps\"\nexec ".escapeshellarg($sleep)." \"\$@\"\n");
        chmod($this->bin.'/sleep', 0o755);
    }

    private function isRunning(int $process): bool
    {
        $stat = is_readable('/proc/'.$process.'/stat') ? file_get_contents('/proc/'.$process.'/stat') : false;

        // A zombie counts as ended, because a container's first process may never reap what the killed watchdog left.
        return false !== $stat && 'Z' !== substr($stat, (int) strrpos($stat, ')') + 2, 1);
    }

    private function givenGitLeavesAPartialBundleAndFails(): void
    {
        $git = (string) new ExecutableFinder()->find('git');
        file_put_contents($this->bin.'/git', <<<SH
            #!/bin/sh
            if [ "\$1" = bundle ]; then
                echo partial > "\$4"
                exit 128
            fi
            exec '{$git}' "\$@"
            SH);
        chmod($this->bin.'/git', 0o755);
    }

    private function whenTheBuildRuns(string $branch, string $model, int $allowance = 600): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-build', $this->history->work, $this->prompt, $branch, $model, '7', (string) $allowance],
            null,
            $this->environment(),
            timeout: 20,
        );
        $run->run();

        return $run;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        // As the container sets them, and without the host's git variables, which would point git at another repository.
        $environment = [
            'HOME' => $this->work,
            'TMPDIR' => $this->work,
            'INTAKT_JOB_OUTPUT' => $this->output,
            'PATH' => $this->bin.':'.getenv('PATH'),
            'RECORD' => $this->history->folder,
        ];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        return $environment;
    }

    private function fileAtTheBundlesHead(string $branch, string $path): string
    {
        $this->history->inMirror('fetch', '--quiet', $this->output.'/handback.bundle', 'refs/heads/'.$branch.':refs/heads/fetched');

        return $this->history->inMirror('show', 'refs/heads/fetched:'.$path)."\n";
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
