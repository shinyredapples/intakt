<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs in the builder's session inside the job's container, so it is tested by running it with sh in a clone, as the builder does.
#[CoversNothing]
final class PrecheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/precheck.sh';

    private BuildHistory $history;
    private string $base = '';

    public function testALinearBranchWithThePullRequestTextPassesThePrecheck(): void
    {
        $this->history->commitFile('built.txt', "built\n", 'feat: build the task');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(0, $run->getExitCode());
        self::assertSame("precheck clean\n", $run->getOutput());
    }

    public function testANewMergeCommitIsReportedWithTheHandBacksReason(): void
    {
        $this->history->startBranch('side', $this->base);
        $this->history->commit('feat: work on the side');
        $this->history->checkout('task');
        $this->history->commit('feat: build the task');
        $this->history->merge('side');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(HandBackRefusal::HoldsMerge->reason()."\n", $run->getOutput());
    }

    public function testANewRootCommitIsReportedAsNoFastForward(): void
    {
        $this->history->commit('feat: build the task');
        $this->history->startBranch('unrelated', $this->base);
        $this->history->restartAsOrphan('unrelated');
        $this->history->commit('feat: start over');
        $this->history->checkout('task');
        $this->history->mergeUnrelated('unrelated');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(HandBackRefusal::NotFastForward->reason()."\n".HandBackRefusal::HoldsMerge->reason()."\n", $run->getOutput());
    }

    public function testABranchThatNoLongerHoldsTheStartingCommitIsReportedAsNoFastForward(): void
    {
        $this->history->commit('feat: an earlier build');
        $this->base = $this->history->commit('feat: the pushed head');
        $this->history->startBranch('task', $this->base.'~1');
        $this->history->commit('feat: rewrite the pushed head');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(HandBackRefusal::NotFastForward->reason()."\n", $run->getOutput());
    }

    public function testABranchWithoutANewCommitIsReportedWithTheBuildsReason(): void
    {
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(BuildVerdict::NoCommits->reason()."\n", $run->getOutput());
    }

    public function testCommitsOnAnotherBranchThanTheTasksAreReportedAsNoCommit(): void
    {
        $this->history->startBranch('elsewhere', $this->base);
        $this->history->commit('feat: build the task elsewhere');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(BuildVerdict::NoCommits->reason()."\n", $run->getOutput());
    }

    public function testAMissingPullRequestTextIsReportedAndThePrecheckFails(): void
    {
        $this->history->commit('feat: build the task');

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(BuildVerdict::NoPullRequestText->reason()."\n", $run->getOutput());
    }

    public function testAPullRequestTextThatIsALinkIsReportedAsMissing(): void
    {
        $this->history->commit('feat: build the task');
        file_put_contents($this->history->folder.'/text.md', "feat: build the task\n");
        new Filesystem()->symlink($this->history->folder.'/text.md', $this->history->work.'/PR.md');

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(BuildVerdict::NoPullRequestText->reason()."\n", $run->getOutput());
    }

    public function testThePrecheckFindsThePullRequestTextFromAFolderOfTheCheckout(): void
    {
        $this->history->commitFile('src/built.txt', "built\n", 'feat: build the task');
        $this->givenThePullRequestText();

        $run = $this->whenThePrecheckRuns($this->history->work.'/src');

        self::assertSame("precheck clean\n", $run->getOutput());
    }

    public function testNamesTheBuilderChoseNeverReachThePrecheckOutput(): void
    {
        $this->history->startBranch('side-PLANT', $this->base);
        $this->history->commitFile('PLANT-file.txt', "PLANT\n", 'feat: PLANT-message');
        $this->history->checkout('task');
        $this->history->commit('feat: PLANT-subject');
        $this->history->merge('side-PLANT');

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertStringNotContainsString('PLANT', $run->getOutput().$run->getErrorOutput());
    }

    public function testAPrecheckWithoutTheSessionsStartIsNoCheckAndSaysSo(): void
    {
        $run = $this->whenThePrecheckRuns(base: '');

        self::assertSame(2, $run->getExitCode());
        self::assertSame("precheck: the session's branch and starting commit are not set, so nothing was checked\n", $run->getOutput());
    }

    public function testAPrecheckOutsideTheCheckoutIsNoCheckAndSaysSo(): void
    {
        new Filesystem()->mkdir($this->history->folder.'/outside');

        $run = $this->whenThePrecheckRuns($this->history->folder.'/outside');

        self::assertSame(2, $run->getExitCode());
        self::assertSame("precheck: run it inside the checkout, so nothing was checked\n", $run->getOutput());
    }

    public function testACommitWithAMissingObjectIsReportedAsOneGitWouldNotWrite(): void
    {
        $this->history->commitFile('built.txt', "built\n", 'feat: build the task');
        $this->givenThePullRequestText();
        $object = trim(new Process(['git', 'rev-parse', 'HEAD:built.txt'], $this->history->work, $this->environment($this->base))->mustRun()->getOutput());
        $gitDir = trim(new Process(['git', 'rev-parse', '--absolute-git-dir'], $this->history->work, $this->environment($this->base))->mustRun()->getOutput());
        new Filesystem()->remove($gitDir.'/objects/'.substr($object, 0, 2).'/'.substr($object, 2));

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(HandBackRefusal::Malformed->reason()."\n", $run->getOutput());
    }

    public function testACommitWithACorruptObjectIsReportedAsOneGitWouldNotWrite(): void
    {
        $this->history->commitFile('built.txt', "built\n", 'feat: build the task');
        $this->givenThePullRequestText();
        $object = trim(new Process(['git', 'rev-parse', 'HEAD:built.txt'], $this->history->work, $this->environment($this->base))->mustRun()->getOutput());
        $gitDir = trim(new Process(['git', 'rev-parse', '--absolute-git-dir'], $this->history->work, $this->environment($this->base))->mustRun()->getOutput());
        $path = $gitDir.'/objects/'.substr($object, 0, 2).'/'.substr($object, 2);
        new Filesystem()->remove($path);
        file_put_contents($path, "unreadable blob\n");

        $run = $this->whenThePrecheckRuns();

        self::assertSame(1, $run->getExitCode());
        self::assertSame(HandBackRefusal::Malformed->reason()."\n", $run->getOutput());
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->base = $this->history->inMirror('rev-parse', 'main');
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function givenThePullRequestText(): void
    {
        file_put_contents($this->history->work.'/PR.md', "feat: build the task\n\n## Summary\n");
    }

    private function whenThePrecheckRuns(?string $folder = null, ?string $base = null): Process
    {
        $run = new Process(['/bin/sh', self::SCRIPT], $folder ?? $this->history->work, $this->environment($base ?? $this->base), timeout: 20);
        $run->run();

        return $run;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(string $base): array
    {
        // As build.sh exports them, and without the host's git variables, which would point git at another repository; the ceiling keeps git from finding the test run's own repository above the folder outside the checkout.
        $environment = [
            'INTAKT_BUILD_BRANCH' => 'task',
            'INTAKT_BUILD_BASE' => $base,
            'GIT_CEILING_DIRECTORIES' => $this->history->folder,
        ];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_') && 'GIT_CEILING_DIRECTORIES' !== $name) {
                $environment[$name] = false;
            }
        }

        return $environment;
    }
}
