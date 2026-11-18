<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the job's container, so it is tested by running it with sh -c as the container does.
#[CoversNothing]
final class CheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/check.sh';

    private BuildHistory $history;
    private string $output = '';
    private string $work = '';

    /** @var array<string, string> */
    private array $gitConfiguration = [];

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        $this->work = $this->history->folder.'/job';
        new Filesystem()->mkdir([$this->output, $this->work]);
    }

    protected function tearDown(): void
    {
        $this->history->remove();
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

    public function testAMergeGitRefusesWithoutAConflictLeavesNotMergedAndRunsNoStep(): void
    {
        $this->history->commitFile('made.txt', "made by the build\n", 'Make a file');
        $this->mainMovesWith('added-on-main.txt', "main moved\n");
        $this->gitConfiguration = ['merge.verifySignatures' => 'true'];

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-merged\n", $this->outputFile('check-result'));
        self::assertStringContainsString('does not have a GPG signature', $this->outputFile('check.log'));
        self::assertFileDoesNotExist($this->work.'/clone/ran');
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
        $this->history->commitFile('shared.txt', "the branch's line\n", 'Write the shared file');
        $this->mainMovesWith('shared.txt', "main's line\n");

        $run = $this->whenTheCheckRuns($this->history->bundle('main..task'), 'task', 'touch ran');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("conflicts-with-main\n", $this->outputFile('check-result'));
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
        $environment = ['HOME' => $this->work, 'TMPDIR' => $this->work, 'INTAKT_JOB_OUTPUT' => $this->output];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        $environment['GIT_CONFIG_COUNT'] = (string) count($this->gitConfiguration);
        foreach (array_keys($this->gitConfiguration) as $index => $key) {
            $environment['GIT_CONFIG_KEY_'.$index] = $key;
            $environment['GIT_CONFIG_VALUE_'.$index] = $this->gitConfiguration[$key];
        }

        return $environment;
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->output.'/'.$name);
    }
}
