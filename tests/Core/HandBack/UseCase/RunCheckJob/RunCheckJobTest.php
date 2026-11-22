<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\Domain\LostContent;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\RunCheckJob\ChangedFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LostContentCheck;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJob;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunCheckJob::class)]
#[CoversClass(LostContentCheck::class)]
#[CoversClass(ChangedFile::class)]
#[CoversClass(CheckJobOutcome::class)]
#[CoversClass(CheckVerdict::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(Paths::class)]
#[UsesClass(LostContent::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class RunCheckJobTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/check.sh';

    private BuildHistory $history;

    private ?JobSpec $ran = null;

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    public function testAJobWhoseOutputSaysPassedPassesTheCheck(): void
    {
        $this->history->commitFile('feature.txt', "new\n", 'Add a feature');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n", 'check.log' => "\$ bin/check --fast\nok\n"]);

        self::assertEquals(new CheckJobOutcome(CheckVerdict::Passed, log: "\$ bin/check --fast\nok\n"), $outcome);
        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertTrue($outcome->passed());
    }

    public function testTheJobAppliesTheBundleInAFreshCloneOfTheMirrorMountedReadOnly(): void
    {
        $this->history->commit('Build work');
        $bundle = $this->history->bundle('main..task');

        $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($bundle, null, []));

        $spec = $this->ranSpec();
        self::assertEquals(
            [new JobInput((string) realpath($this->history->mirror), '/input/mirror'), new JobInput((string) realpath($bundle), '/input/handback.bundle')],
            $spec->inputs,
        );
        self::assertSame(['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-check', '/input/mirror', '/input/handback.bundle', 'task', '--'], array_slice([...$spec->command], 0, 8));
        self::assertSame('job-image', $spec->image);
    }

    public function testTheJobRunsTheGatesStepsAndReachesOnlyItsRegistries(): void
    {
        $this->history->commit('Build work');

        $this->whenTheJobLeaves(['check-result' => "passed\n"]);

        $spec = $this->ranSpec();
        self::assertSame(['composer install --no-interaction --no-progress', 'bin/check --fast'], array_slice([...$spec->command], 8));
        self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], [...$spec->egress->hosts]);
    }

    public function testAConfiguredCommandReplacesTheGatesOwnSteps(): void
    {
        $this->history->commit('Build work');

        $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), 'make check', []));

        self::assertSame(['make check'], array_slice([...$this->ranSpec()->command], 8));
    }

    public function testAGateWithoutRegistriesGetsNoNetwork(): void
    {
        $this->history->commit('Build work');
        $gate = self::createStub(Gate::class);
        $gate->method('checkSteps')->willReturn(['true']);
        $gate->method('registryHosts')->willReturn([]);

        (void) $this->checkJob($this->runnerLeaving(new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(['check-result' => "passed\n"]))), $gate)
            ->run(new CheckJob(new HandBack($this->history->mirror, 'task', $this->history->bundle('main..task'), false, new JobCredentials()), 'job-image', null, new Paths()));

        self::assertTrue($this->ranSpec()->egress->isNone());
    }

    public function testAFailedStepFailsTheCheckWithItsExitCodeAndLog(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "failed 2\n", 'check.log' => "tests failed\n"]);

        self::assertEquals(new CheckJobOutcome(CheckVerdict::Failed, 2, log: "tests failed\n"), $outcome);
    }

    public function testABundleTheJobCouldNotApplyFailsTheCheck(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "not-applied\n"]);

        self::assertEquals(new CheckJobOutcome(CheckVerdict::NotApplied), $outcome);
    }

    public function testABranchTheJobCouldNotMergeWithCurrentMainFailsTheCheckAsAConflictWithMain(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "conflicts-with-main\n"]);

        self::assertEquals(new CheckJobOutcome(CheckVerdict::ConflictsWithMain), $outcome);
    }

    public function testABranchGitRefusedToMergeWithoutAConflictFailsTheCheckAsNotMerged(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "not-merged\n"]);

        self::assertEquals(new CheckJobOutcome(CheckVerdict::NotMerged), $outcome);
    }

    public function testTheResultIsReadFromTheOutputDirectoryAndNotFromTheContainersExitCode(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "failed 1\n"], end: new JobEnd(JobCause::Exited, 0));

        self::assertSame(CheckVerdict::Failed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testAJobThatLeftNoResultFailsTheCheckEvenWhenItExitedWithZero(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check.log' => "ok\n"], end: new JobEnd(JobCause::Exited, 0));

        self::assertEquals(new CheckJobOutcome(CheckVerdict::NoResult, log: "ok\n"), $outcome);
    }

    public function testAResultThatIsNotExactlyWhatTheScriptWritesFailsTheCheck(): void
    {
        $this->history->commit('Build work');

        foreach (["passed\nfailed 1\n", 'passed', "failed 0\n", "PASSED\n", "failed 1000\n", " passed\n"] as $result) {
            $outcome = $this->whenTheJobLeaves(['check-result' => $result]);

            self::assertSame(CheckVerdict::NoResult, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null, $result);
        }
    }

    public function testAJobKilledAtItsMemoryLimitFailsTheCheckWhateverItsOutputSays(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], end: new JobEnd(JobCause::MemoryKilled, 137));

        self::assertEquals(new CheckJobOutcome(CheckVerdict::MemoryKilled), $outcome);
    }

    public function testAJobThatRanOutOfTimeFailsTheCheckWhateverItsOutputSays(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], end: new JobEnd(JobCause::TimedOut, 137));

        self::assertEquals(new CheckJobOutcome(CheckVerdict::TimedOut), $outcome);
    }

    public function testAnOutputTheRunnerRefusesFailsTheCheck(): void
    {
        $this->history->commit('Build work');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::tooMany(1000));

        $outcome = $this->checkJob($runner)->run($this->job($this->history->bundle('main..task'), null, []));

        self::assertEquals(new CheckJobOutcome(CheckVerdict::NoResult), $outcome);
    }

    public function testARefusedHandBackStartsNoJob(): void
    {
        $this->history->commit('Build work');
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');

        $outcome = $this->checkJob($runner)->run($this->job($this->history->file('no bundle'), null, []));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::Unreadable), $outcome);
    }

    public function testAFileThatLostMostOfItsContentFailsTheCheckBeforeAnyJobStarts(): void
    {
        $this->givenMainHolds('docs/guide.md', $this->lines(120));
        $this->history->commitFile('docs/guide.md', "1\n", 'Shorten the guide');
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');

        $outcome = $this->checkJob($runner)->run($this->job($this->history->bundle('main..task'), null, []));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame(CheckVerdict::LostContent, $outcome->verdict);
        self::assertSame([['docs/guide.md', 120, 1]], $this->lostIn($outcome));
    }

    public function testAFileThePlanNamesAsRewrittenMayLoseItsContent(): void
    {
        $this->givenMainHolds('docs/guide.md', $this->lines(120));
        $this->history->commitFile('docs/guide.md', "1\n", 'Rewrite the guide');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['docs/guide.md']));

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testARemovedFileTheTasksPlanDoesNotNameFailsTheCheck(): void
    {
        $this->givenMainHolds('src/Old.php', $this->lines(80));
        $this->history->removeFile('src/Old.php', 'Remove the old class');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['src/Other.php']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['src/Old.php', 80, 0]], $this->lostIn($outcome));
    }

    public function testARemovedFileThePlanNamesPasses(): void
    {
        $this->givenMainHolds('src/Old.php', $this->lines(80));
        $this->history->removeFile('src/Old.php', 'Remove the old class');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['src/Old.php']));

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testAFileMovedUnchangedHasNotLostItsContent(): void
    {
        $this->givenMainHolds('src/Old.php', $this->lines(80));
        $this->history->moveFile('src/Old.php', 'src/New.php', 'Move the class');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"]);

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testAFileMainShrankAfterTheBranchStartedIsNotTheChangesLoss(): void
    {
        $this->givenMainHolds('docs/guide.md', $this->lines(120));
        $this->history->commit('Build work');
        $this->history->checkout('main');
        $this->history->commitFile('docs/guide.md', "1\n", 'Shorten the guide on main');
        $this->history->push('main');
        $this->history->checkout('task');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"]);

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testARemovedSubmoduleIsNoLossOfContent(): void
    {
        $this->history->checkout('main');
        $this->history->commitSubmodule('vendor/library', str_repeat('a', 40));
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->removeFile('vendor/library', 'Remove the submodule');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"]);

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testAFileReplacedByASubmoduleHasLostItsContent(): void
    {
        $this->givenMainHolds('docs/guide.md', $this->lines(120));
        $this->history->commitSubmodule('docs/guide.md', str_repeat('a', 40));

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"]);

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['docs/guide.md', 120, 0]], $this->lostIn($outcome));
    }

    public function testAMovedFileRewrittenBeyondRecognitionPassesWhenThePlanNamesItsNewPath(): void
    {
        $this->givenMainHolds('docs/old.md', $this->lines(120));
        $this->history->moveFile('docs/old.md', 'docs/new.md', 'Move the guide');
        $this->history->commitFile('docs/new.md', "1\n", 'Rewrite the guide');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['docs/new.md']));

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
    }

    public function testAMovedFileRewrittenBeyondRecognitionFailsWhenThePlanDoesNotNameIt(): void
    {
        $this->givenMainHolds('docs/old.md', $this->lines(120));
        $this->history->moveFile('docs/old.md', 'docs/new.md', 'Move the guide');
        $this->history->commitFile('docs/new.md', "1\n", 'Rewrite the guide');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['docs/other.md']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['docs/old.md', 120, 0]], $this->lostIn($outcome));
    }

    public function testAnUnnamedFileMovedOverANamedPathThatExistedAtTheBaseIsStillReportedLost(): void
    {
        $this->givenMainHolds('docs/guide.md', $this->lines(120));
        $this->history->checkout('main');
        $this->history->commitFile('docs/keep.md', $this->lines(120), 'Add the file to keep');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->removeFile('docs/guide.md', 'Remove the guide');
        $this->history->moveFile('docs/keep.md', 'docs/guide.md', 'Move the kept file over it');
        $this->history->commitFile('docs/guide.md', "1\n", 'Rewrite the guide');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['docs/guide.md']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['docs/keep.md', 120, 0]], $this->lostIn($outcome));
    }

    public function testAnUnnamedFileMovedOntoTheNamedPathAndBackIsStillReportedLost(): void
    {
        $this->givenMainHolds('src/Important.php', $this->lines(120));
        $this->history->checkout('main');
        $this->history->commitFile('docs/old.md', $this->lines(120), 'Add the guide');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->moveFile('src/Important.php', 'docs/new.md', 'Move the class onto the named path');
        $this->history->moveFile('docs/new.md', 'src/Important.php', 'Move it back');
        $this->history->moveFile('docs/old.md', 'docs/new.md', 'Move the guide');
        $this->history->commitFile('docs/new.md', "1\n", 'Rewrite the guide');
        $this->history->commitFile('src/Important.php', "1\n", 'Destroy the class');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['docs/new.md']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['src/Important.php', 120, 1]], $this->lostIn($outcome));
    }

    public function testAnUnnamedFileThatPassedThroughTheNamedPathBeforeAnotherTookItIsStillReportedLost(): void
    {
        $this->givenMainHolds('a.txt', $this->lines(120));
        $this->history->checkout('main');
        $this->history->commitFile('b.txt', $this->lines(120), 'Add B');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->moveFile('a.txt', 'named.txt', 'Move A onto the named path');
        $this->history->moveFile('named.txt', 'temp.txt', 'Move A aside');
        $this->history->moveFile('b.txt', 'named.txt', 'Move B onto the named path');
        $this->history->moveFile('temp.txt', 'a.txt', 'Restore the path of A');
        $this->history->commitFile('a.txt', "1\n", 'Rewrite A');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['named.txt']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['a.txt', 120, 1]], $this->lostIn($outcome));
    }

    public function testAFileMovedOntoANamedPathThatWasThenRemovedAndCreatedAnewIsStillReportedLost(): void
    {
        $this->givenMainHolds('important.txt', $this->lines(120));
        $this->history->moveFile('important.txt', 'named.txt', 'Move the important file onto the named path');
        $this->history->removeFile('named.txt', 'Remove it');
        $this->history->commitFile('named.txt', "1\n", 'Create an unrelated file at the named path');

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $this->job($this->history->bundle('main..task'), null, ['named.txt']));

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['important.txt', 120, 0]], $this->lostIn($outcome));
    }

    public function testAFileMovedOntoANamedPathThatWasThenRemovedAndCreatedAnewInAMergeOfMainIsStillReportedLost(): void
    {
        $this->givenMainHolds('important.txt', $this->lines(120));
        $this->history->moveFile('important.txt', 'named.txt', 'Move the important file onto the named path');
        $this->history->removeFile('named.txt', 'Remove it');
        $this->history->checkout('main');
        $this->history->commitFile('other.txt', "other\n", 'Advance main');
        $this->history->push('main');
        $this->history->checkout('task');
        $this->history->startMerge('main');
        $this->history->commitFile('named.txt', "1\n", 'Merge main and create an unrelated file at the named path');

        $job = new CheckJob(new HandBack($this->history->mirror, 'task', $this->history->bundle('main..task'), true, new JobCredentials()), 'job-image', null, new Paths('named.txt'));

        $outcome = $this->whenTheJobLeaves(['check-result' => "passed\n"], $job);

        self::assertInstanceOf(CheckJobOutcome::class, $outcome);
        self::assertSame([['important.txt', 120, 0]], $this->lostIn($outcome));
    }

    private function givenMainHolds(string $path, string $content): void
    {
        $this->history->checkout('main');
        $this->history->commitFile($path, $content, 'Add '.$path);
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
    }

    /**
     * @param array<string, string> $files
     */
    private function whenTheJobLeaves(array $files, ?CheckJob $job = null, ?JobEnd $end = null): CheckJobOutcome|RefusedHandBack
    {
        $runner = $this->runnerLeaving(new JobResult($end ?? new JobEnd(JobCause::Exited, 1), OutputFiles::of($files)));

        return $this->checkJob($runner)->run($job ?? $this->job($this->history->bundle('main..task'), null, []));
    }

    private function runnerLeaving(JobResult $result): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($result): JobResult {
            $this->ran = $spec;

            return $result;
        });

        return $runner;
    }

    /**
     * @param list<string> $named
     */
    private function job(string $bundle, ?string $command, array $named): CheckJob
    {
        return new CheckJob(new HandBack($this->history->mirror, 'task', $bundle, false, new JobCredentials()), 'job-image', $command, new Paths(...$named));
    }

    private function checkJob(JobRunner $runner, ?Gate $gate = null): RunCheckJob
    {
        $git = new RepositoryGit(new HardenedGit());

        return new RunCheckJob(
            new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks')),
            new LostContentCheck($git),
            $runner,
            $gate ?? new PhpGate(),
            new Filesystem(),
            self::SCRIPT,
        );
    }

    private function ranSpec(): JobSpec
    {
        self::assertInstanceOf(JobSpec::class, $this->ran);

        return $this->ran;
    }

    /**
     * @return list<array{string, int, int}>
     */
    private function lostIn(CheckJobOutcome $outcome): array
    {
        return array_map(static fn (LostContent $lost): array => [$lost->path, $lost->linesBefore, $lost->linesAfter], $outcome->lostContent);
    }

    private function lines(int $count): string
    {
        return implode('', array_map(static fn (int $line): string => sprintf("line %03d of the file\n", $line), range(1, $count)));
    }
}
