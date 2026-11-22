<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does, with a scripted CLI in place of Claude Code.
#[CoversNothing]
final class BuildScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/build.sh';
    // Seconds a killed watchdog's sleep may take to end, because the kill is delivered asynchronously and a loaded runner may finish its exit only after the script returned.
    private const int WATCHDOG_DEADLINE = 5;
    // The script's own bound on git bundle create, repeated here because the script is shell and holds it as a literal.
    private const int BUNDLE_TIME_LIMIT = 30;

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';
    private string $bin = '';
    private string $prompt = '';
    private string $gates = '4';
    // False removes the variable, so a value the test run itself was started with never reaches the script.
    private string|false $inherited = false;
    // False for the same reason, because the test run itself may run inside a CLI session that sets both.
    private string|false $inheritedTimeout = false;
    // Seconds the script may run, so a git waiting on a pipe fails its test instead of holding the suite.
    private int $timeout = 20;
    private ?string $startFolder = null;
    private ?string $pathHead = null;
    private ?string $source = null;

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
            ['-p', '--model', 'claude-opus-5-5', '--max-turns', '7', '--output-format', 'json', '--dangerously-skip-permissions', '--settings', '{"env":{"BASH_DEFAULT_TIMEOUT_MS":"1800000","BASH_MAX_TIMEOUT_MS":"1800000"}}'],
            explode("\n", trim((string) file_get_contents($this->history->folder.'/arguments'))),
        );
    }

    public function testTheSessionIsStartedWithASettingsArgumentWhoseEnvGivesBothBashTimeoutsTheToolTimeLimitInMilliseconds(): void
    {
        $this->givenTheCli('printf "%s\n" "$@" > "$RECORD/arguments"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame(
            ['env' => ['BASH_DEFAULT_TIMEOUT_MS' => '1800000', 'BASH_MAX_TIMEOUT_MS' => '1800000']],
            $this->settingsArgument(),
        );
    }

    // Claude Code applies the env of the clone's own settings files after it starts, so only the command-line settings, which rank above them, keep the limit.
    #[TestWith(['.claude/settings.json'])]
    #[TestWith(['.claude/settings.local.json'])]
    public function testACloneWhoseSettingsFileSetsBothBashTimeoutsHigherStillStartsTheSessionWithTheLimitAsItsSettingsArgument(string $file): void
    {
        $this->history->commitFile($file, '{"env":{"BASH_DEFAULT_TIMEOUT_MS":"7200000","BASH_MAX_TIMEOUT_MS":"7200000"}}', 'chore: ask for more time');
        $this->givenTheCli('cp "'.$file.'" "$RECORD/project-settings.json"; printf "%s\n" "$@" > "$RECORD/arguments"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertStringContainsString('7200000', (string) file_get_contents($this->history->folder.'/project-settings.json'));
        self::assertSame(
            ['env' => ['BASH_DEFAULT_TIMEOUT_MS' => '1800000', 'BASH_MAX_TIMEOUT_MS' => '1800000']],
            $this->settingsArgument(),
        );
    }

    public function testTheBuildScriptExportsTheGateCountItIsGivenAsIntaktGateSlots(): void
    {
        $this->givenTheCli('printf "%s\n" "$INTAKT_GATE_SLOTS" > "$RECORD/gates"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("4\n", (string) file_get_contents($this->history->folder.'/gates'));
    }

    public function testTheBuildScriptGivenNoGateCountLeavesIntaktGateSlotsUnset(): void
    {
        $this->givenTheCli('printf "%s\n" "${INTAKT_GATE_SLOTS-unset}" > "$RECORD/gates"');

        $this->gates = '';

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("unset\n", (string) file_get_contents($this->history->folder.'/gates'));
    }

    public function testTheBuildScriptGivenNoGateCountKeepsAnIntaktGateSlotsItsEnvironmentAlreadySets(): void
    {
        $this->givenTheCli('printf "%s\n" "${INTAKT_GATE_SLOTS-unset}" > "$RECORD/gates"');

        $this->gates = '';
        $this->inherited = '3';

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("3\n", (string) file_get_contents($this->history->folder.'/gates'));
    }

    public function testTheBuildScriptGivenAGateCountReplacesAnIntaktGateSlotsItsEnvironmentAlreadySets(): void
    {
        $this->givenTheCli('printf "%s\n" "${INTAKT_GATE_SLOTS-unset}" > "$RECORD/gates"');

        $this->inherited = '3';

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("4\n", (string) file_get_contents($this->history->folder.'/gates'));
    }

    public function testTheBuildScriptGivesTheCliItsToolTimeLimitInMillisecondsAsBothBashTimeouts(): void
    {
        $this->givenTheCli('printf "%s %s\n" "${BASH_DEFAULT_TIMEOUT_MS-unset}" "${BASH_MAX_TIMEOUT_MS-unset}" > "$RECORD/timeouts"');

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("1800000 1800000\n", (string) file_get_contents($this->history->folder.'/timeouts'));
    }

    public function testTheBuildScriptReplacesBashTimeoutsItsEnvironmentAlreadySets(): void
    {
        $this->givenTheCli('printf "%s %s\n" "${BASH_DEFAULT_TIMEOUT_MS-unset}" "${BASH_MAX_TIMEOUT_MS-unset}" > "$RECORD/timeouts"');

        $this->inheritedTimeout = '7200000';

        $this->whenTheBuildRuns('task', 'claude-opus-5-5');

        self::assertSame("1800000 1800000\n", (string) file_get_contents($this->history->folder.'/timeouts'));
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

    public function testASessionThatPutsAFolderWhereACutMarkerCouldBeIsStillCutAtItsAllowance(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            mkdir "$TMPDIR/cut"
            sleep 30
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 3);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
        self::assertStringContainsString("claude cut after 3 seconds, its commits are kept\n", $this->outputFile('build.log'));
    }

    public function testASessionThatWritesACutMarkerItselfIsNotLoggedAsCut(): void
    {
        $this->givenTheCli('touch "$TMPDIR/cut"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertStringNotContainsString('cut after', $this->outputFile('build.log'));
    }

    public function testASessionThatKillsItselfBeforeItsAllowanceIsNotLoggedAsCut(): void
    {
        $this->givenTheCli('kill -s KILL $$');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 3);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertStringContainsString("claude exited with 137\n", $this->outputFile('build.log'));
        self::assertStringNotContainsString('cut after', $this->outputFile('build.log'));
    }

    public function testASessionKilledBySigkillLongBeforeItsAllowanceIsBundledWithoutWaitingForTheAllowance(): void
    {
        // The run's limit sits far below the allowance, so a script that waits out the allowance fails here by timing out.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            kill -s KILL $$
            SH);
        $this->timeout = 15;

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 120);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
        self::assertStringContainsString("claude exited with 137\n", $this->outputFile('build.log'));
        self::assertStringNotContainsString('cut after', $this->outputFile('build.log'));
    }

    public function testASessionKilledBySigkillBeforeItsAllowanceLeavesNoWatchdogBehind(): void
    {
        $this->givenTheCli('until [ -s "$RECORD/sleeps" ]; do :; done; kill -s KILL $$');
        $this->givenSleepRecordsItsProcess();
        $this->timeout = 15;

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 120);

        self::assertSame(0, $run->getExitCode());
        $sleeps = $this->recordedSleeps();
        self::assertNotSame([], $sleeps);
        self::assertSame([], $this->stillRunningAfter($sleeps, self::WATCHDOG_DEADLINE));
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
        // Waits for the watchdog's sleep, because a session that ends at once could end the watchdog before its sleep is recorded.
        $this->givenTheCli('until [ -s "$RECORD/sleeps" ]; do :; done; exit 0');
        $this->givenSleepRecordsItsProcess();

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 31);

        self::assertSame(0, $run->getExitCode());
        $sleeps = $this->recordedSleeps();
        self::assertNotSame([], $sleeps);
        self::assertSame([], $this->stillRunningAfter($sleeps, self::WATCHDOG_DEADLINE));
    }

    public function testAWatchdogSleepStillEndingWhenTheBuildReturnsIsNotLeftBehindOnceItHasEnded(): void
    {
        $this->givenTheCli('until [ -s "$RECORD/sleeps" ]; do :; done; exit 0');
        $this->givenSleepRecordsAProcessThatEndsOnlyAfterTheBuild();

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5', 31);
        $sleeps = $this->recordedSleeps();
        $runningWhenTheBuildReturned = array_values(array_filter($sleeps, $this->isRunning(...)));
        touch($this->history->folder.'/build-returned');

        self::assertSame(0, $run->getExitCode());
        self::assertNotSame([], $runningWhenTheBuildReturned);
        self::assertSame([], $this->stillRunningAfter($sleeps, self::WATCHDOG_DEADLINE));
    }

    public function testTheStandInEndsOnceItsTestRunnerIsGoneWhileTheRecordFolderStillExists(): void
    {
        $runner = new Process([(string) new ExecutableFinder()->find('sleep'), '30']);
        $runner->start();
        $this->givenSleepRecordsAProcessThatEndsOnlyAfterTheBuild((int) $runner->getPid());
        new Process([$this->bin.'/sleep', '0'], env: ['RECORD' => $this->history->folder])->mustRun();
        $standIn = $this->recordedSleeps()[0];
        $runningWhileTheRunnerLived = $this->isRunning($standIn);

        $runner->stop(0);

        self::assertTrue($runningWhileTheRunnerLived);
        self::assertSame([], $this->stillRunningAfter([$standIn], self::WATCHDOG_DEADLINE));
        self::assertDirectoryExists($this->history->folder);
        self::assertFileDoesNotExist($this->history->folder.'/build-returned');
    }

    public function testTheStandInEndsOnceItsTestRunnerIsAZombieItsParentHasNotReaped(): void
    {
        // proc_open, because Symfony's Process reaps its child on every status read and the runner must stay unreaped.
        $runner = proc_open([(string) new ExecutableFinder()->find('sleep'), '30'], [], $pipes);
        self::assertNotFalse($runner);
        $pid = proc_get_status($runner)['pid'];
        $this->givenSleepRecordsAProcessThatEndsOnlyAfterTheBuild($pid);
        new Process([$this->bin.'/sleep', '0'], env: ['RECORD' => $this->history->folder])->mustRun();
        $standIn = $this->recordedSleeps()[0];
        $runningWhileTheRunnerLived = $this->isRunning($standIn);

        proc_terminate($runner, SIGKILL);
        $runnerEnded = [] === $this->stillRunningAfter([$pid], self::WATCHDOG_DEADLINE);
        $runnerUnreaped = is_dir('/proc/'.$pid);
        $standInStillRunning = $this->stillRunningAfter([$standIn], self::WATCHDOG_DEADLINE);
        proc_close($runner);

        self::assertTrue($runningWhileTheRunnerLived);
        self::assertTrue($runnerEnded);
        self::assertTrue($runnerUnreaped);
        self::assertSame([], $standInStillRunning);
    }

    public function testTheWaitForTheWatchdogStillReportsAProcessThatOutlivesItsDeadline(): void
    {
        $lingering = new Process([(string) new ExecutableFinder()->find('sleep'), '30']);
        $lingering->start();
        $process = (int) $lingering->getPid();

        $stillRunning = $this->stillRunningAfter([$process], 1);
        $lingering->stop(0);

        self::assertSame([$process], $stillRunning);
    }

    public function testTheWaitForTheWatchdogCountsAProcessWhoseStateCannotBeReadOnceCheckedAsEnded(): void
    {
        // A folder, because it passes a readability check and then fails the read, as the stat file of a process reaped in between does.
        $stat = $this->history->folder.'/reaped';
        new Filesystem()->mkdir($stat);

        self::assertFalse($this->statShowsALiveProcess($stat));
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

    public function testAnIncludeThatWaitsOnAPipeInTheClonesGitConfigDoesNotStopTheBundleOfTheBranch(): void
    {
        // Nobody writes into the pipe, so a git that reads the clone's own settings after the session waits until the job is timed out.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            mkfifo "$TMPDIR/settings"
            git config include.path "$TMPDIR/settings"
            SH);
        $this->timeout = 10;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/settings');
        }

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testACommonFolderTheCliNamesInTheCloneDoesNotStopTheBundleOfTheBranch(): void
    {
        // Git reads the settings of the folder a commondir file names, so the include waits on a pipe nobody writes into.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            mkfifo "$TMPDIR/settings"
            cp -R .git "$TMPDIR/common"
            printf '[include]\n\tpath = %s\n' "$TMPDIR/settings" >> "$TMPDIR/common/config"
            echo "$TMPDIR/common" > .git/commondir
            SH);
        $this->timeout = 10;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/settings');
        }

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testASha256BuildCloneStillHandsBackABundleOfTheBranchAfterItsSettingsAreReplaced(): void
    {
        $this->source = $this->history->folder.'/sha256';
        foreach ([['init', '--quiet', '--object-format=sha256', '--initial-branch=task', $this->source], ['-C', $this->source, 'commit', '--quiet', '--allow-empty', '-m', 'chore: start']] as $command) {
            new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@intakt.invalid', ...$command], null, $this->environment())->mustRun();
        }
        $this->givenTheCli('git commit --quiet --allow-empty -m "feat: build the task" && git rev-parse HEAD > "$RECORD/head"');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertStringStartsWith("# v3 git bundle\n@object-format=sha256\n", $this->outputFile('handback.bundle'));
        self::assertStringContainsString(trim((string) file_get_contents($this->history->folder.'/head')).' refs/heads/task', $this->outputFile('handback.bundle'));
    }

    public function testCloneSettingsThatCannotBeReplacedHandNoBundleBack(): void
    {
        // A failing remove, because a test cannot make settings the job's own user cannot remove.
        file_put_contents($this->bin.'/rm', "#!/bin/sh\nexit 1\n");
        chmod($this->bin.'/rm', 0o755);
        $this->givenTheCli('echo built > built.txt && git add built.txt && git commit --quiet -m "feat: build"');

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertStringContainsString("no bundle is handed back: the clone's git settings could not be replaced", $this->outputFile('build.log'));
    }

    public function testARepositoryTheCliMakesAboveACloneWhoseHeadItRemovedDoesNotStopTheBundleOfTheBranch(): void
    {
        // Without a HEAD the clone's .git is no repository, so a git that searches upward finds the work area's, whose include waits on a pipe nobody writes into.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            git init --quiet "$TMPDIR"
            mkfifo "$TMPDIR/settings"
            printf '[include]\n\tpath = %s\n' "$TMPDIR/settings" >> "$TMPDIR/.git/config"
            rm .git/HEAD
            SH);
        $this->timeout = 10;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/settings');
        }

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testAGitFolderTheCliTurnsIntoALinkHandsNoBundleBackAndLeavesTheLinkedFilesAlone(): void
    {
        $this->givenTheCli(<<<'SH'
            git commit --quiet --allow-empty -m 'feat: build the task'
            mv .git "$TMPDIR/elsewhere"
            ln -s "$TMPDIR/elsewhere" .git
            echo outside > "$TMPDIR/elsewhere/commondir"
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertStringEqualsFile($this->work.'/elsewhere/commondir', "outside\n");
        self::assertStringContainsString("no bundle is handed back: the clone's git settings could not be replaced", $this->outputFile('build.log'));
    }

    public function testAnAlternatesFileThatIsAPipeDoesNotStopTheBundleOfTheBranch(): void
    {
        // Git reads the alternates file for every object it looks up, so a pipe nobody writes into holds the bundle until the job is timed out.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            mkfifo .git/objects/info/alternates
            SH);
        $this->timeout = 10;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/clone/.git/objects/info/alternates');
        }

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    public function testABranchRefTheCliTurnsIntoAPipeEndsTheJobWithinTheBundleLimitWithNoBundleAndTheLoggedReason(): void
    {
        // Git reads the branch's ref to bundle it, so a pipe nobody writes into would hold the bundle until the job is timed out.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            rm .git/refs/heads/task
            mkfifo .git/refs/heads/task
            SH);
        $this->timeout = self::BUNDLE_TIME_LIMIT + 15;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/clone/.git/refs/heads/task');
        }

        $this->thenTheBundleWasCut($run);
    }

    public function testAPackedRefsFileTheCliTurnsIntoAPipeEndsTheJobWithinTheBundleLimitWithNoBundleAndTheLoggedReason(): void
    {
        // The branch is packed first, so git has to read packed-refs to find it.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            git pack-refs --all
            rm -f .git/packed-refs
            mkfifo .git/packed-refs
            SH);
        $this->timeout = self::BUNDLE_TIME_LIMIT + 15;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/clone/.git/packed-refs');
        }

        $this->thenTheBundleWasCut($run);
    }

    public function testAShallowFileTheCliTurnsIntoAPipeEndsTheJobWithinTheBundleLimitWithNoBundleAndTheLoggedReason(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            rm -f .git/shallow
            mkfifo .git/shallow
            SH);
        $this->timeout = self::BUNDLE_TIME_LIMIT + 15;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/clone/.git/shallow');
        }

        $this->thenTheBundleWasCut($run);
    }

    public function testABuildLogTheCliTurnsIntoAPipeStillHandsTheBundleBackWithinTheLimit(): void
    {
        // The first append after the session would wait on a pipe nobody reads, so the job would hold its slot.
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            rm -f "$INTAKT_JOB_OUTPUT/build.log"
            mkfifo "$INTAKT_JOB_OUTPUT/build.log"
            SH);
        $this->timeout = 20;

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode());
        self::assertFileExists($this->output.'/handback.bundle');
        self::assertStringContainsString('claude exited with', $this->outputFile('build.log'));
    }

    public function testATimeoutWithoutLongOptionsStillHandsTheBundleBack(): void
    {
        $timeout = (string) new ExecutableFinder()->find('timeout');
        file_put_contents($this->bin.'/timeout', <<<SH
            #!/bin/sh
            case "\$1" in
                --*) echo 'timeout: unrecognized option' >&2; exit 1 ;;
            esac
            exec '{$timeout}' "\$@"
            SH);
        chmod($this->bin.'/timeout', 0o755);
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function testATimeoutThatRejectsTheKillOptionEndsTheJobBeforeTheSessionWithTheLoggedReason(int $status): void
    {
        // Found only at the bundle step, such a timeout would spend the session and then lose its commits behind exit 0.
        $timeout = (string) new ExecutableFinder()->find('timeout');
        $this->givenTheTimeout(<<<SH
            case "\$1" in
                -*) echo 'timeout: unrecognized option' >&2; exit {$status} ;;
            esac
            exec '{$timeout}' "\$@"
            SH);
        $this->givenTheCli(<<<'SH'
            touch "$RECORD/ran"
            git commit --quiet --allow-empty -m 'feat: build the task'
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        $this->thenTheJobEndedBeforeTheSessionBecauseOfItsTimeout($run);
    }

    public function testAnImageWithoutATimeoutEndsTheJobBeforeTheSessionWithTheLoggedReason(): void
    {
        $this->givenTheTimeout(<<<'SH'
            echo 'sh: timeout: not found' >&2
            exit 127
            SH);
        $this->givenTheCli(<<<'SH'
            touch "$RECORD/ran"
            git commit --quiet --allow-empty -m 'feat: build the task'
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        $this->thenTheJobEndedBeforeTheSessionBecauseOfItsTimeout($run);
    }

    public function testARelativePathEntryDoesNotLetThePreflightCheckAnotherTimeoutThanTheBundleStepUses(): void
    {
        // Because the relative entry names a file in the folder the script starts in and none in the clone, where the bundle step runs.
        $folder = $this->history->folder.'/relative';
        mkdir($folder.'/image-bin', 0o755, true);
        $timeout = (string) new ExecutableFinder()->find('timeout');
        file_put_contents($folder.'/image-bin/timeout', "#!/bin/sh\nexec '{$timeout}' \"\$@\"\n");
        chmod($folder.'/image-bin/timeout', 0o755);
        $this->givenTheTimeout(<<<'SH'
            echo 'timeout: unsupported option' >&2
            exit 2
            SH);
        $this->givenTheCli(<<<'SH'
            touch "$RECORD/ran"
            git commit --quiet --allow-empty -m 'feat: build the task'
            SH);
        $this->startFolder = $folder;
        $this->pathHead = 'image-bin:'.$this->bin;

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        $this->thenTheJobEndedBeforeTheSessionBecauseOfItsTimeout($run);
    }

    public function testATimeoutThatReportsTheStatusOfTheGitItEndedStillLogsTheCut(): void
    {
        // The cut must be logged for this timeout too, because it ends with 143, the status of a git ended by TERM, and not with its own 124.
        $timeout = (string) new ExecutableFinder()->find('timeout');
        $this->givenTheTimeout("exec '{$timeout}' --preserve-status \"\$@\"");
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            rm -f .git/shallow
            mkfifo .git/shallow
            SH);
        $this->timeout = self::BUNDLE_TIME_LIMIT + 15;

        try {
            $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');
        } finally {
            $this->releaseThePipe($this->work.'/clone/.git/shallow');
        }

        $this->thenTheBundleWasCut($run);
    }

    public function testObjectsTheCliMovedOutOfTheCloneAndNamedInAlternatesHandNoBundleBack(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            mv .git/objects "$TMPDIR/objects"
            mkdir -p .git/objects/info
            echo "$TMPDIR/objects" > .git/objects/info/alternates
            echo "$TMPDIR/objects" > .git/objects/info/http-alternates
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertFileDoesNotExist($this->work.'/clone/.git/objects/info/alternates');
        self::assertFileDoesNotExist($this->work.'/clone/.git/objects/info/http-alternates');
    }

    public function testAnObjectsInfoFolderTheCliTurnsIntoALinkHandsNoBundleBackAndLeavesTheLinkedFilesAlone(): void
    {
        $this->givenTheCli(<<<'SH'
            git commit --quiet --allow-empty -m 'feat: build the task'
            mkdir "$TMPDIR/elsewhere"
            echo outside > "$TMPDIR/elsewhere/alternates"
            rm -rf .git/objects/info
            ln -s "$TMPDIR/elsewhere" .git/objects/info
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertStringEqualsFile($this->work.'/elsewhere/alternates', "outside\n");
        self::assertStringContainsString("no bundle is handed back: the clone's git settings could not be replaced", $this->outputFile('build.log'));
    }

    public function testACliThatTakesAwayEveryPermissionOnTheOutputStillHandsBackTheBundleOfTheBranch(): void
    {
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            chmod 000 "$INTAKT_JOB_OUTPUT"
            SH);

        $run = $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertSame("built\n", $this->fileAtTheBundlesHead('task', 'built.txt'));
        self::assertStringContainsString("claude exited with 0\n", $this->outputFile('build.log'));
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

    public function testAQuestionTheCliWritesInTheCheckoutIsHandedBack(): void
    {
        $this->givenTheCli(<<<'SH'
            printf '{"question":"Which store?","choices":["SQLite"],"recommendation":"SQLite"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame('{"question":"Which store?","choices":["SQLite"],"recommendation":"SQLite"}', $this->outputFile('question.json'));
    }

    public function testAQuestionTheBranchAlreadyHeldIsNotHandedBackWhenTheCliLeavesItUntouched(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli('true');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
    }

    public function testAQuestionTheBranchAlreadyHeldIsHandedBackWhenTheCliRewritesIt(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli(<<<'SH'
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame('{"question":"New?","choices":["b"],"recommendation":"b"}', $this->outputFile('question.json'));
    }

    // The checkout converts the line endings, so it holds other bytes than the commit: only the checkout's own bytes at the start tell an untouched question from a rewritten one.
    #[TestWith(["QUESTION.json text eol=crlf\n"])]
    #[TestWith(["* text=auto eol=crlf\n"])]
    public function testAQuestionTheCheckoutConvertsTheLineEndingsOfIsNotHandedBackWhenTheCliLeavesItUntouched(string $attributes): void
    {
        $this->history->commitFile('.gitattributes', $attributes, 'chore: check the question out with other line endings');
        $this->history->commitFile('QUESTION.json', "{\"question\":\"Old?\",\"choices\":[\"a\"],\"recommendation\":\"a\"}\n", 'docs: keep an old question');
        $this->givenTheCli('cp QUESTION.json "$RECORD/checked-out.json"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertStringEndsWith("}\r\n", (string) file_get_contents($this->history->folder.'/checked-out.json'));
        self::assertFileDoesNotExist($this->output.'/question.json');
    }

    public function testAQuestionTheCheckoutConvertsTheLineEndingsOfIsHandedBackWhenTheCliRewritesIt(): void
    {
        $this->history->commitFile('.gitattributes', "QUESTION.json text eol=crlf\n", 'chore: check the question out with other line endings');
        $this->history->commitFile('QUESTION.json', "{\"question\":\"Old?\",\"choices\":[\"a\"],\"recommendation\":\"a\"}\n", 'docs: keep an old question');
        $this->givenTheCli(<<<'SH'
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame('{"question":"New?","choices":["b"],"recommendation":"b"}', $this->outputFile('question.json'));
    }

    public function testAQuestionTheBranchAlreadyHeldIsNotHandedBackWhenTheCliOnlyCommitsItsCheckedOutBytes(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli(<<<'SH'
            echo 'built' > built.txt
            git add built.txt
            git commit --quiet -m 'feat: build the task'
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
        self::assertFileExists($this->output.'/handback.bundle');
    }

    public function testAnUntouchedQuestionInACloneWhoseGitConfigTheCliBrokeIsNotHandedBack(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli("echo '[broken' >> .git/config");

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
    }

    // A hash git takes in the clone follows the clone's object format, which the builder can write, so the same bytes would get another hash after the session.
    public function testAnUntouchedQuestionInACloneWhoseObjectFormatTheCliSwitchedIsNotHandedBack(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli(<<<'SH'
            git config core.repositoryformatversion 1
            git config extensions.objectFormat sha256
            git config extensions.objectFormat > "$RECORD/object-format"
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame("sha256\n", (string) file_get_contents($this->history->folder.'/object-format'));
        self::assertFileDoesNotExist($this->output.'/question.json');
    }

    public function testARewrittenQuestionInACloneWhoseObjectFormatTheCliSwitchedIsHandedBack(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheCli(<<<'SH'
            git config core.repositoryformatversion 1
            git config extensions.objectFormat sha256
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertSame('{"question":"New?","choices":["b"],"recommendation":"b"}', $this->outputFile('question.json'));
    }

    public function testAQuestionWhoseDigestFailedBeforeTheSessionIsNotHandedBackEvenWhenTheCliRewritesIt(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheDigestFailsOnItsCall(1);
        $this->givenTheCli(<<<'SH'
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
        self::assertStringContainsString("QUESTION.json is not handed back: it could not be hashed before the session\n", $this->outputFile('build.log'));
    }

    public function testADigestThatFailedBeforeTheSessionIsLoggedEvenWhenTheCliRemovesTheQuestion(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheDigestFailsOnItsCall(1);
        $this->givenTheCli('rm QUESTION.json');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
        self::assertStringContainsString("QUESTION.json is not handed back: it could not be hashed before the session\n", $this->outputFile('build.log'));
    }

    public function testAQuestionWhoseDigestFailsAfterTheSessionIsNotHandedBackEvenWhenTheCliRewritesIt(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheDigestFailsOnItsCall(2);
        $this->givenTheCli(<<<'SH'
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
        self::assertStringContainsString("QUESTION.json is not handed back: it could not be hashed after the session\n", $this->outputFile('build.log'));
    }

    public function testADigestThatPrintsNothingAfterTheSessionHandsNoQuestionBack(): void
    {
        $this->history->commitFile('QUESTION.json', '{"question":"Old?","choices":["a"],"recommendation":"a"}', 'docs: keep an old question');
        $this->givenTheDigestFailsOnItsCall(2, 0);
        $this->givenTheCli(<<<'SH'
            printf '{"question":"New?","choices":["b"],"recommendation":"b"}' > QUESTION.json
            SH);

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
        self::assertStringContainsString("QUESTION.json is not handed back: it could not be hashed after the session\n", $this->outputFile('build.log'));
    }

    public function testAQuestionThatIsALinkIsNotHandedBack(): void
    {
        $this->givenTheCli('ln -s "$RECORD/prompt.md" QUESTION.json');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
    }

    public function testAQuestionTheCliWritesIntoTheOutputIsNotHandedBack(): void
    {
        $this->givenTheCli('echo planted > "$INTAKT_JOB_OUTPUT/question.json"');

        $this->whenTheBuildRuns('task', 'claude-sonnet-5-5');

        self::assertFileDoesNotExist($this->output.'/question.json');
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

    private function givenTheTimeout(string $script): void
    {
        file_put_contents($this->bin.'/timeout', "#!/bin/sh\n".$script."\n");
        chmod($this->bin.'/timeout', 0o755);
    }

    private function thenTheJobEndedBeforeTheSessionBecauseOfItsTimeout(Process $run): void
    {
        self::assertSame(1, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertStringContainsString("the image's timeout cannot bound the bundle step\n", $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->history->folder.'/ran');
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
    }

    // A git still waiting on the pipe would outlive the test, and an empty file takes the pipe's place because git reads its settings more than once.
    private function releaseThePipe(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        // Read and write, because that open never waits for the other end.
        $pipe = fopen($path, 'r+');
        if (false === $pipe) {
            throw new RuntimeException('the pipe could not be opened');
        }

        unlink($path);
        touch($path);
        fclose($pipe);
    }

    // Each sleep reports its own process id, because the CI image has no procps to look it up with.
    private function givenSleepRecordsItsProcess(): void
    {
        $sleep = new ExecutableFinder()->find('sleep');
        self::assertNotNull($sleep);
        file_put_contents($this->bin.'/sleep', "#!/bin/sh\necho \$\$ >> \"\$RECORD/sleeps\"\nexec ".escapeshellarg($sleep)." \"\$@\"\n");
        chmod($this->bin.'/sleep', 0o755);
    }

    // A stand-in that reports itself only once in a session of its own, so the group kill misses it and it runs until the build returned, as a killed sleep still in its exit does; it has no time limit a slow build could outlast, and also ends once tearDown removes the folder or the test runner's process is gone or a zombie, because a failed test must leave nothing and a killed runner never reaches tearDown, and its parent may not reap it.
    private function givenSleepRecordsAProcessThatEndsOnlyAfterTheBuild(?int $runner = null): void
    {
        $runner ??= (int) getmypid();
        $sleep = escapeshellarg((string) new ExecutableFinder()->find('sleep'));
        file_put_contents($this->bin.'/stand-in', <<<'SH'
            #!/bin/sh
            echo $$ > "$RECORD/stand-in.$2"
            while [ ! -e "$RECORD/build-returned" ] && [ -d "$RECORD" ]; do
                case "$(sed -n 's/.*) \(.\).*/\1/p' "/proc/$3/stat" 2>/dev/null)" in
                    ''|Z|X) exit 0 ;;
                esac
                "$1" 0.05
            done
            SH);
        chmod($this->bin.'/stand-in', 0o755);
        $standIn = escapeshellarg($this->bin.'/stand-in');
        file_put_contents($this->bin.'/sleep', <<<SH
            #!/bin/sh
            setsid {$standIn} {$sleep} \$\$ {$runner} > /dev/null 2>&1 &
            until [ -s "\$RECORD/stand-in.\$\$" ]; do :; done
            cat "\$RECORD/stand-in.\$\$" >> "\$RECORD/sleeps"
            echo \$\$ >> "\$RECORD/sleeps"
            exec {$sleep} "\$@"
            SH);
        chmod($this->bin.'/sleep', 0o755);
    }

    /**
     * @return list<int>
     */
    private function recordedSleeps(): array
    {
        $recorded = file($this->history->folder.'/sleeps', FILE_IGNORE_NEW_LINES);

        return array_map(intval(...), false === $recorded ? [] : $recorded);
    }

    /**
     * @param list<int> $processes
     *
     * @return list<int>
     */
    private function stillRunningAfter(array $processes, int $seconds): array
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        $running = array_values(array_filter($processes, $this->isRunning(...)));
        while ([] !== $running && hrtime(true) < $deadline) {
            usleep(20_000);
            $running = array_values(array_filter($running, $this->isRunning(...)));
        }

        return $running;
    }

    private function isRunning(int $process): bool
    {
        return $this->statShowsALiveProcess('/proc/'.$process.'/stat');
    }

    // One read and no check before it, because a process reaped between a check and the read would turn the read into a warning.
    private function statShowsALiveProcess(string $stat): bool
    {
        $state = @file_get_contents($stat);

        // A zombie counts as ended, because a container's first process may never reap what the killed watchdog left.
        return is_string($state) && '' !== $state && 'Z' !== substr($state, (int) strrpos($state, ')') + 2, 1);
    }

    private function thenTheBundleWasCut(Process $run): void
    {
        self::assertSame(0, $run->getExitCode(), $this->outputFile('build.log'));
        self::assertFileDoesNotExist($this->output.'/handback.bundle');
        self::assertFileDoesNotExist($this->output.'/handback.bundle.lock');
        self::assertStringContainsString(
            sprintf("no bundle is handed back: git bundle create was cut after %d seconds\n", self::BUNDLE_TIME_LIMIT),
            $this->outputFile('build.log'),
        );
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

    // Only the given call fails or prints nothing, because the script hashes once before the session and once after it, and each side has a rule of its own.
    private function givenTheDigestFailsOnItsCall(int $call, int $status = 1): void
    {
        $digest = (string) new ExecutableFinder()->find('sha256sum');
        file_put_contents($this->bin.'/sha256sum', <<<SH
            #!/bin/sh
            echo call >> "\$RECORD/digests"
            if [ "\$(grep -c call "\$RECORD/digests")" -eq {$call} ]; then
                exit {$status}
            fi
            exec '{$digest}' "\$@"
            SH);
        chmod($this->bin.'/sha256sum', 0o755);
    }

    private function whenTheBuildRuns(string $branch, string $model, int $allowance = 600): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-build', $this->source ?? $this->history->work, $this->prompt, $branch, $model, '7', (string) $allowance, $this->gates, '1800'],
            $this->startFolder,
            $this->environment(),
            timeout: $this->timeout,
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
            'PATH' => ($this->pathHead ?? $this->bin).':'.getenv('PATH'),
            'RECORD' => $this->history->folder,
            'INTAKT_GATE_SLOTS' => $this->inherited,
            'BASH_DEFAULT_TIMEOUT_MS' => $this->inheritedTimeout,
            'BASH_MAX_TIMEOUT_MS' => $this->inheritedTimeout,
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

    private function settingsArgument(): mixed
    {
        $arguments = explode("\n", trim((string) file_get_contents($this->history->folder.'/arguments')));
        $at = array_search('--settings', $arguments, true);
        self::assertIsInt($at);

        return json_decode($arguments[$at + 1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
