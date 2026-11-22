<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does.
#[CoversNothing]
final class CheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/check.sh';

    private const string LOCK = "{\"packages\": [], \"packages-dev\": []}\n";

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';
    private string $path = '';

    /** @var array<string, string> */
    private array $gitConfiguration = [];

    /** @var array<string, string> */
    private array $jobVariables = [];

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        $this->work = $this->history->folder.'/job';
        new Filesystem()->mkdir([$this->output, $this->work]);
    }

    protected function tearDown(): void
    {
        new Process(['chmod', '-R', 'u+w', $this->history->folder])->run();
        $this->history->remove();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function settingsThatWouldChangeACleanMerge(): iterable
    {
        yield 'a merge option that leaves out the commit' => [['branch.task.mergeOptions' => '--no-commit']];
        yield 'a squash merge option' => [['branch.task.mergeOptions' => '--squash']];
        yield 'a merge option that keeps main alone' => [['branch.task.mergeOptions' => '-s ours']];
        yield 'a merge option that signs with a failing signer' => [['branch.task.mergeOptions' => '--gpg-sign', 'gpg.program' => 'false']];
        yield 'commit signing with a failing signer' => [['commit.gpgSign' => 'true', 'gpg.program' => 'false']];
        yield 'signature verification of the branch' => [['merge.verifySignatures' => 'true']];
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function settingsThatWouldHideAConflict(): iterable
    {
        yield 'a merge option that takes the branch side' => [['branch.task.mergeOptions' => '-X theirs']];
        yield 'a merge option that takes the main side' => [['branch.task.mergeOptions' => '-X ours']];
        yield 'a merge option that keeps main alone' => [['branch.task.mergeOptions' => '-s ours']];
    }

    public function testStepsThatAllPassLeavePassedAndTheirOutputInTheLog(): void
    {
        $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'cat made.txt', 'echo second step');

        self::assertSame(0, $run->getExitCode());
        self::assertSame("passed\n", $this->outputFile('check-result'));
        self::assertSame("\$ cat made.txt\nmade by the build\n\$ echo second step\nsecond step\n", $this->outputFile('check.log'));
    }

    public function testTheFirstFailingStepEndsTheCheckWithItsExitCode(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'exit 3', 'echo never');

        self::assertSame(3, $run->getExitCode());
        self::assertSame("failed 3\n", $this->outputFile('check-result'));
        self::assertStringNotContainsString('never', $this->outputFile('check.log'));
    }

    public function testTheStepsRunInAFreshCloneOfTheMirrorAtTheBundlesHead(): void
    {
        $head = $this->history->commit('Build work');
        file_put_contents($this->history->work.'/left-by-the-build.txt', 'not committed');

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            'test "$(git rev-parse HEAD)" = '.$head,
            'git merge-base --is-ancestor main HEAD',
            'test ! -e left-by-the-build.txt',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testTheStepsKeepTheJobsGitEnvironment(): void
    {
        $this->history->commit('Build work');
        $this->gitConfiguration = ['intakt.probe' => 'kept'];

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test "$(git config intakt.probe)" = kept');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testABundleWithoutTheTasksBranchLeavesNotAppliedAndRunsNoStep(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'another-task', 'touch ran');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-applied\n", $this->outputFile('check-result'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMissingBundleLeavesNotApplied(): void
    {
        $run = $this->whenTheCheckRuns($this->history->folder.'/missing.bundle', 'task', 'true');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-applied\n", $this->outputFile('check-result'));
    }

    public function testABranchThatAlreadyHoldsCurrentMainRunsItsStepsOnItsOwnHeadWithoutAMerge(): void
    {
        $this->mainMovesWith('added-on-main.txt', "main moved\n");
        $head = $this->history->merge('main');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test "$(git rev-parse HEAD)" = '.$head);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testABranchWithoutCommitsOfItsOwnRunsItsStepsOnMainWithoutAMerge(): void
    {
        $main = $this->history->inMirror('rev-parse', 'main');

        $run = $this->whenTheCheckRuns($this->history->bundle('task'), 'task', 'test "$(git rev-parse HEAD)" = '.$main);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testABranchBehindAMainThatMovedRunsItsStepsOnTheHeadMergedIntoCurrentMainWithMainAsTheFirstParent(): void
    {
        $head = $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        $main = $this->mainMovesWith('added-on-main.txt', "main moved\n");

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            'test -f made.txt',
            'test -f added-on-main.txt',
            'test "$(git rev-parse HEAD^1)" = '.$main,
            'test "$(git rev-parse HEAD^2)" = '.$head,
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testAUnionMergedFileHoldsWhatCisMergeOfTheBranchIntoMainHolds(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('.gitattributes', "list.txt merge=union\n", 'Merge the list by union');
        $this->history->commitFile('list.txt', "base\n", 'Start the list');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->merge('main');
        $head = $this->history->commitFile('list.txt', "the branch's line\n", 'Write the branch line');
        $bundle = $this->history->bundle('main..task');
        $this->mainMovesWith('list.txt', "main's line\n");
        $this->history->checkout('main');
        $this->history->merge($head);
        $merged = (string) file_get_contents($this->history->work.'/list.txt');

        $run = $this->whenTheCheckRuns($bundle, 'task', 'cat list.txt');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringEndsWith("\$ cat list.txt\n".$merged, $this->outputFile('check.log'));
    }

    public function testTheMergeWithCurrentMainHasAConventionalSubjectSoTheCommitSubjectGatePassesIt(): void
    {
        $this->history->commit('Build work');
        $this->mainMovesWith('added-on-main.txt', "main moved\n");

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test "$(git log -1 --format=%s)" = "chore(check): merge the branch into current main"');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
    }

    /**
     * @param array<string, string> $configuration
     */
    #[DataProvider('settingsThatWouldChangeACleanMerge')]
    public function testAGitSettingOfTheJobsEnvironmentDoesNotChangeACleanMergeWithCurrentMain(array $configuration): void
    {
        $this->gitConfiguration = $configuration;

        $this->thenACleanMergeEndsPassedOnItsMergeCommit();
    }

    /**
     * @param array<string, string> $configuration
     */
    #[DataProvider('settingsThatWouldHideAConflict')]
    public function testAGitSettingOfTheJobsEnvironmentDoesNotHideAConflictWithCurrentMain(array $configuration): void
    {
        $this->gitConfiguration = $configuration;

        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testGitSettingsGivenOnTheJobsCommandLineDoNotHideAConflictWithCurrentMain(): void
    {
        $this->jobVariables = ['GIT_CONFIG_PARAMETERS' => "'branch.task.mergeoptions'='-X theirs'"];

        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testTheGitConfigurationInTheJobsHomeDoesNotHideAConflictWithCurrentMain(): void
    {
        new Filesystem()->dumpFile($this->work.'/.gitconfig', "[branch \"task\"]\n\tmergeOptions = -X theirs\n");

        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testTheGitAttributesInTheJobsHomeDoNotHideAConflictWithCurrentMain(): void
    {
        new Filesystem()->dumpFile($this->work.'/.config/git/attributes', "shared.txt merge=union\n");

        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testTheGitAttributesInTheImagesDefaultTemplateDoNotHideAConflictWithCurrentMain(): void
    {
        // A git in front of the real one whose default template is a fixture, because an image's git copies its compiled-in template into every clone.
        $git = (string) new ExecutableFinder()->find('git');
        $template = $this->history->folder.'/template';
        $shim = $this->history->folder.'/shim';
        new Filesystem()->dumpFile($template.'/info/attributes', "shared.txt merge=union\n");
        new Filesystem()->dumpFile($shim.'/git', "#!/bin/sh\nGIT_TEMPLATE_DIR='$template' exec '$git' \"\$@\"\n");
        new Filesystem()->chmod($shim.'/git', 0o700);
        $this->path = $shim.PATH_SEPARATOR.getenv('PATH');

        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testAMirrorOwnedByAnotherUserIsStillClonedAndItsStepsRun(): void
    {
        // A git in front of the real one that takes the mirror for another user's, because a test cannot hand the mirror to another user; it sets the variable after own_git cleared the environment.
        $git = (string) new ExecutableFinder()->find('git');
        $shim = $this->history->folder.'/shim';
        new Filesystem()->dumpFile($shim.'/git', <<<SH
            #!/bin/sh
            for argument in "\$@"; do
                if [ "\$argument" = clone ]; then
                    GIT_TEST_ASSUME_DIFFERENT_OWNER=1
                    export GIT_TEST_ASSUME_DIFFERENT_OWNER
                fi
            done
            exec '{$git}' "\$@"
            SH);
        new Filesystem()->chmod($shim.'/git', 0o700);
        $this->path = $shim.PATH_SEPARATOR.getenv('PATH');
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'echo ran');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testTheExceptionForAMirrorOwnedByAnotherUserIsLeftToTheCloneAndNeverReachesTheSteps(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            sprintf("! GIT_TEST_ASSUME_DIFFERENT_OWNER=1 git -C '%s' rev-parse --git-dir", $this->history->mirror),
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testTheCloneHoldsNothingOfGitsDefaultTemplate(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test ! -e .git/hooks', 'test ! -e .git/info', 'test ! -e .git/description');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testAGitVariableWithBytesThatAreNoTextInTheJobsLocaleIsUnsetLikeAnyOther(): void
    {
        $this->gitConfiguration = ['branch.task.mergeOptions' => "-X theirs \xff"];
        $this->jobVariables = ['LC_ALL' => 'C.UTF-8'];

        $this->thenACleanMergeEndsPassedOnItsMergeCommit();
    }

    public function testSignatureVerificationDoesNotRefuseTheMergeOfABranchNamedLikeGitsMergeStateFile(): void
    {
        $head = $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        // This name, because a branch called MERGE_HEAD answers a revision lookup of it although no merge is in progress.
        $this->history->startBranch('MERGE_HEAD', 'task');
        $bundle = $this->history->bundle('main..MERGE_HEAD');
        $main = $this->mainMovesWith('added-on-main.txt', "main moved\n");
        $this->gitConfiguration = ['merge.verifySignatures' => 'true'];

        $run = $this->whenTheCheckRuns(
            $bundle,
            'MERGE_HEAD',
            'test -f made.txt',
            'test "$(git rev-parse HEAD^1)" = '.$main,
            'test "$(git rev-parse HEAD^2)" = '.$head,
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
    }

    public function testTheMergeWithCurrentMainRunsNoHookSoARefusingMergeHookDoesNotStopACleanMerge(): void
    {
        $hooks = $this->history->folder.'/hooks';
        new Filesystem()->dumpFile($hooks.'/pre-merge-commit', "#!/bin/sh\necho 'merge hook refused' >&2\nexit 1\n");
        new Filesystem()->chmod($hooks.'/pre-merge-commit', 0o700);
        $this->gitConfiguration = ['core.hooksPath' => $hooks];

        $this->thenACleanMergeEndsPassedOnItsMergeCommit();
        self::assertStringNotContainsString('merge hook refused', $this->outputFile('check.log'));
    }

    public function testABranchWithNoHistoryInCommonWithMainLeavesNotMergedAndRunsNoStep(): void
    {
        $this->history->commit('Build work');
        $this->mainMovesWith('added-on-main.txt', "main moved\n");
        $this->history->restartAsOrphan('task');
        $this->history->commitFile('made.txt', "made by the build\n", 'Start over');

        $run = $this->whenTheCheckRuns($this->history->bundle('task'), 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("not-merged\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
        self::assertStringContainsString('refusing to merge unrelated histories', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMergeThatExitsWithSuccessButLeavesNoMergeCommitLeavesNotMergedAndRunsNoStep(): void
    {
        $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        $this->mainMovesWith('added-on-main.txt', "main moved\n");
        // A git in front of the real one that ends every merge before its commit, as an option the script does not override would.
        $git = (string) new ExecutableFinder()->find('git');
        $shim = $this->history->folder.'/shim';
        new Filesystem()->dumpFile($shim.'/git', "#!/bin/sh\nfor argument; do\n    if [ \"\$argument\" = merge ]; then\n        exec '$git' \"\$@\" --no-commit\n    fi\ndone\nexec '$git' \"\$@\"\n");
        new Filesystem()->chmod($shim.'/git', 0o700);
        $this->path = $shim.PATH_SEPARATOR.getenv('PATH');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("not-merged\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
        self::assertStringContainsString('the merge left no merge commit of', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAFileTheBranchChangedAndMainDeletedLeavesConflictsWithMainAndRunsNoStep(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('shared.txt', "base\n", 'Add the shared file');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->merge('main');
        $this->history->commitFile('shared.txt', "the branch's line\n", 'Change the shared file');
        $bundle = $this->history->bundle('main..task');
        $this->history->checkout('main');
        $this->history->removeFile('shared.txt', 'Delete the shared file');
        $this->history->push('main');
        $this->history->checkout('task');

        $run = $this->whenTheCheckRuns($bundle, 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("conflicts-with-main\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
        self::assertStringContainsString('CONFLICT (modify/delete)', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMirrorWhoseHeadIsDetachedAtAMainThatMovedRunsTheStepsOnTheHeadMergedIntoThatMain(): void
    {
        $head = $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        $main = $this->mainMovesWith('added-on-main.txt', "main moved\n");
        $this->history->inMirror('update-ref', '--no-deref', 'HEAD', $main);

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            'test -f added-on-main.txt',
            'test "$(git rev-parse HEAD^1)" = '.$main,
            'test "$(git rev-parse HEAD^2)" = '.$head,
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
    }

    public function testTheMergeWithCurrentMainTakesTheChecksOwnIdentityAndNoneFromTheJob(): void
    {
        $this->history->commit('Build work');
        $this->mainMovesWith('added-on-main.txt', "main moved\n");

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test "$(git log -1 --format=%cn/%ce)" = intakt-check/check@intakt.invalid');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
    }

    public function testABranchThatConflictsWithCurrentMainLeavesConflictsWithMainAndRunsNoStep(): void
    {
        $this->thenAConflictingBranchEndsConflictsWithMain();
    }

    public function testAConflictThatLeavesNoUnmergedPathLeavesConflictsWithMainAndRunsNoStep(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('old/a.txt', "first file\n", 'Add first file');
        $this->history->commitFile('old/b.txt', "second file\n", 'Add second file');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->merge('main');
        $this->history->commitFile('old/new.txt', "new file\n", 'Add a file to the directory');
        $bundle = $this->history->bundle('main..task');
        // Main splits the directory in two, so git cannot tell where the branch's new file belongs and stops without an unmerged path.
        $this->history->checkout('main');
        new Filesystem()->mkdir([$this->history->work.'/left', $this->history->work.'/right']);
        $this->history->moveFile('old/a.txt', 'left/a.txt', 'Move first file');
        $this->history->moveFile('old/b.txt', 'right/b.txt', 'Move second file');
        $this->history->push('main');

        $run = $this->whenTheCheckRuns($bundle, 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("conflicts-with-main\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
        self::assertStringContainsString('CONFLICT', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMirrorWhoseHeadNamesNoCommitRunsTheStepsOnTheBranchHeadWithoutAMerge(): void
    {
        $head = $this->history->commit('Build work');
        $this->history->inMirror('symbolic-ref', 'HEAD', 'refs/heads/gone');

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'test "$(git rev-parse HEAD)" = '.$head);

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    private function thenACleanMergeEndsPassedOnItsMergeCommit(): void
    {
        $head = $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        $main = $this->mainMovesWith('added-on-main.txt', "main moved\n");

        $run = $this->whenTheCheckRuns(
            $this->history->bundle('main..task'),
            'task',
            'test -f made.txt',
            'test -f added-on-main.txt',
            'test "$(git rev-parse HEAD^1)" = '.$main,
            'test "$(git rev-parse HEAD^2)" = '.$head,
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
    }

    private function thenAConflictingBranchEndsConflictsWithMain(): void
    {
        $this->history->commitFile('shared.txt', "the branch's line\n", 'Write the shared file');
        $this->mainMovesWith('shared.txt', "main's line\n");

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("conflicts-with-main\n", $this->outputFile('check-result'), $this->outputFile('check.log'));
        self::assertStringContainsString('CONFLICT (', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
    }

    public function testAMountedSnapshotIsCopiedWritableIntoTheCloneBeforeTheFirstStep(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/composer/installed.json' => "{\"packages\": []}\n", '.hidden/kept.txt' => "kept\n"]);

        $run = $this->whenTheCheckRunsWith(
            $this->dependencyOptions($snapshot, self::LOCK),
            $this->history->bundle('main..task'),
            'test -f vendor/composer/installed.json',
            'test -f .hidden/kept.txt',
            'touch vendor/composer/autoload_real.php',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
        self::assertStringStartsWith('$ copy the dependency snapshot '.$snapshot."\n\$ test -f", $this->outputFile('check.log'));
        self::assertFileExists($snapshot.'/vendor/composer/installed.json');
    }

    public function testATreeThatAlreadyHoldsAFolderOfTheSnapshotGetsNoneOfItAndInstallsItself(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $this->history->commitFile('vendor/committed.txt', "committed\n", 'Commit a vendor folder');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/composer/installed.json' => "{\"packages\": []}\n"]);

        $run = $this->whenTheCheckRunsWith($this->dependencyOptions($snapshot, self::LOCK), $this->history->bundle('main..task'), 'test ! -e vendor/composer/installed.json', 'test -f vendor/committed.txt');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringContainsString("the tree already holds vendor, so the check installs its dependencies itself\n", $this->outputFile('check.log'));
    }

    public function testASnapshotEntryNamedWithALineBreakDoesNotReplaceTheCommittedFileOfThatName(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $this->history->commitFile("two\nlines", "checked tree\n", 'Add a file with a line break in its name');
        $snapshot = $this->readOnlySnapshotHolding(["two\nlines" => "snapshot\n"]);

        $run = $this->whenTheCheckRunsWith($this->dependencyOptions($snapshot, self::LOCK), $this->history->bundle('main..task'), 'true');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("checked tree\n", file_get_contents($this->work."/clone/two\nlines"));
        self::assertStringContainsString("the tree already holds two\nlines, so the check installs", $this->outputFile('check.log'));
    }

    public function testEveryEntryOfACopiedSnapshotIsMadeWritableAlsoWhenItsNameHoldsALineBreak(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(["two\nlines/kept.txt" => "kept\n"]);

        $run = $this->whenTheCheckRunsWith($this->dependencyOptions($snapshot, self::LOCK), $this->history->bundle('main..task'), "test -w 'two\nlines/kept.txt'");

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
    }

    public function testASnapshotMadeForALockFileMainChangedSinceIsNotCopiedIntoTheMergedTree(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->commit('Build work');
        $bundle = $this->history->bundle('main..task');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/from-the-old-install' => "old\n"]);
        $this->mainMovesWith('composer.lock', "{\"packages\": [{\"name\": \"psr/log\"}]}\n");

        $run = $this->whenTheCheckRunsWith($this->dependencyOptions($snapshot, self::LOCK), $bundle, 'test ! -e vendor');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringContainsString("the merged tree does not hold the lock files the snapshot was made for, so the check installs its dependencies itself\n", $this->outputFile('check.log'));
    }

    public function testALockFileWhoseBlobIsMissingCopiesNothing(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);

        $run = $this->whenTheCheckRunsWith(['--dependencies', $snapshot, 'install', 'generate', 'composer.lock'], $this->history->bundle('main..task'), 'test ! -e vendor');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
    }

    public function testASnapshotWithoutAnyLockFileToCompareIsNotCopied(): void
    {
        $this->history->commit('Build work');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);

        $run = $this->whenTheCheckRunsWith(['--dependencies', $snapshot, 'install', 'generate'], $this->history->bundle('main..task'), 'test ! -e vendor');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
    }

    public function testACopiedSnapshotRunsTheGenerateStepInPlaceOfTheGatesInstall(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);

        $run = $this->whenTheCheckRunsWith(
            ['--dependencies', $snapshot, 'exit 9', 'touch generated', 'composer.lock', $this->blobOf(self::LOCK)],
            $this->history->bundle('main..task'),
            'exit 9',
            'test -f generated',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringContainsString("\$ touch generated\n\$ test -f generated\n", $this->outputFile('check.log'));
    }

    public function testACheckThatCouldNotCopyTheSnapshotRunsTheGatesInstallAndNotTheGenerateStep(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $this->history->commitFile('vendor/committed.txt', "committed\n", 'Commit a vendor folder');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);

        $run = $this->whenTheCheckRunsWith(
            ['--dependencies', $snapshot, 'touch installed', 'exit 9', 'composer.lock', $this->blobOf(self::LOCK)],
            $this->history->bundle('main..task'),
            'touch installed',
            'test -f installed',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringNotContainsString('exit 9', $this->outputFile('check.log'));
    }

    public function testAPartialCopyThatIsRemovedLeavesTheGatesInstallToRunOnATreeWithoutIt(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);
        $this->givenACopyThatStopsHalfway();

        $run = $this->whenTheCheckRunsWith(
            ['--dependencies', $snapshot, 'touch installed', 'exit 9', 'composer.lock', $this->blobOf(self::LOCK)],
            $this->history->bundle('main..task'),
            'touch installed',
            'test ! -e vendor',
        );

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertSame("passed\n", $this->outputFile('check-result'));
        self::assertStringContainsString('the dependency snapshot could not be copied', $this->outputFile('check.log'));
    }

    public function testAPartialCopyThatCannotBeRemovedStopsTheCheckBeforeAnyStepAndLeavesNoResult(): void
    {
        $this->history->commitFile('composer.lock', self::LOCK, 'Lock the dependencies');
        $snapshot = $this->readOnlySnapshotHolding(['vendor/installed.json' => "{}\n"]);
        $shim = $this->givenACopyThatStopsHalfway();
        new Filesystem()->dumpFile($shim.'/rm', "#!/bin/sh\nexit 1\n");
        new Filesystem()->chmod($shim.'/rm', 0o700);

        $run = $this->whenTheCheckRunsWith(
            ['--dependencies', $snapshot, 'touch installed', 'exit 9', 'composer.lock', $this->blobOf(self::LOCK)],
            $this->history->bundle('main..task'),
            'touch installed',
        );

        self::assertSame(1, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertFileExists($this->work.'/clone/vendor/installed.json');
        self::assertFileDoesNotExist($this->work.'/clone/installed');
        self::assertFileDoesNotExist($this->output.'/check-result');
        self::assertStringContainsString(
            "the partial copy of the dependency snapshot could not be removed, so the check stops without a result\n",
            $this->outputFile('check.log'),
        );
        self::assertStringNotContainsString('$ touch installed', $this->outputFile('check.log'));
    }

    private function givenACopyThatStopsHalfway(): string
    {
        // A cp in front of the real one that leaves one file of the snapshot in the clone and fails, because a test cannot make a real copy stop halfway.
        $shim = $this->history->folder.'/shim';
        $partial = $this->work.'/clone/vendor';
        new Filesystem()->dumpFile($shim.'/cp', "#!/bin/sh\nmkdir -p '$partial'\ntouch '$partial/installed.json'\nexit 1\n");
        new Filesystem()->chmod($shim.'/cp', 0o700);
        $this->path = $shim.PATH_SEPARATOR.getenv('PATH');

        return $shim;
    }

    /**
     * @return list<string>
     */
    private function dependencyOptions(string $snapshot, string $lock): array
    {
        return ['--dependencies', $snapshot, 'composer install', 'composer install', 'composer.lock', $this->blobOf($lock)];
    }

    private function blobOf(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    public function testACheckWithoutASnapshotRunsItsStepsWithoutAVendorFolder(): void
    {
        $this->history->commit('Build work');

        $run = $this->whenTheCheckRunsWith([], $this->history->bundle('main..task'), 'test ! -e vendor');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('check.log'));
        self::assertStringNotContainsString('dependency snapshot', $this->outputFile('check.log'));
    }

    /**
     * @param array<string, string> $files
     */
    private function readOnlySnapshotHolding(array $files): string
    {
        $snapshot = $this->history->folder.'/snapshot';

        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($snapshot.'/'.$path, $content);
        }

        new Process(['chmod', '-R', 'a-w', $snapshot])->mustRun();

        return $snapshot;
    }

    /**
     * @param list<string> $options
     */
    private function whenTheCheckRunsWith(array $options, string $bundle, string ...$steps): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-check', $this->history->mirror, $bundle, 'task', ...$options, '--', ...array_values($steps)],
            null,
            $this->environment(),
        );
        $run->run();

        return $run;
    }

    private function mainMovesWith(string $path, string $content): string
    {
        $this->history->checkout('main');
        $main = $this->history->commitFile($path, $content, 'Move main');
        $this->history->push('main');
        $this->history->checkout('task');

        return $main;
    }

    private function whenTheCheckRuns(string $bundle, string $branch, string ...$steps): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-check', $this->history->mirror, $bundle, $branch, '--', ...array_values($steps)],
            null,
            $this->environment(),
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
        $environment = ['HOME' => $this->work, 'XDG_CONFIG_HOME' => false, 'TMPDIR' => $this->work, 'INTAKT_JOB_OUTPUT' => $this->output];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        if ('' !== $this->path) {
            $environment['PATH'] = $this->path;
        }

        $environment['GIT_CONFIG_COUNT'] = (string) count($this->gitConfiguration);
        foreach (array_keys($this->gitConfiguration) as $index => $key) {
            $environment['GIT_CONFIG_KEY_'.$index] = $key;
            $environment['GIT_CONFIG_VALUE_'.$index] = $this->gitConfiguration[$key];
        }

        return [...$environment, ...$this->jobVariables];
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
