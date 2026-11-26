<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RegressionCheckout;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RegressionTestFiles;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RunRegressionTests;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunRegressionTests::class)]
#[CoversClass(RegressionCheckout::class)]
#[CoversClass(RegressionRuns::class)]
#[UsesClass(RegressionTestFiles::class)]
#[UsesClass(RegressionRun::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(Paths::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(HardenedGit::class)]
final class RunRegressionTestsTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/regression.sh';

    private BuildHistory $history;

    /** @var list<JobSpec> */
    private array $ran = [];

    public function testTheBaseRunAndTheHeadRunAreTwoSeparateJobsWithTheirOwnResults(): void
    {
        $head = $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["failed\n", "passed\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::Failed, $runs->base);
        self::assertSame(RegressionRun::Passed, $runs->head);
        self::assertSame(['tests/FooTest.php'], [...$runs->tests]);
        self::assertSame(['regression-base-'.$head, 'regression-head-'.$head], array_map(static fn (JobSpec $spec): string => $spec->id->value, $this->ran));
    }

    public function testTheBaseRunChecksOutTheBaseCommitWithTheHeadsTestFilesOverIt(): void
    {
        $base = $this->history->inMirror('rev-parse', 'main');
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $this->whenTheJobsLeave(["passed\n", "passed\n"]);

        $command = [...$this->ran[0]->command];
        self::assertSame(['intakt-regression', '/input/mirror', '/input/handback.bundle', 'task', $base, 'task', '1', 'composer install --no-interaction --no-progress', 'tests/FooTest.php', ...new PhpGate()->testSteps(new Paths('tests/FooTest.php'))], array_slice($command, 3));
    }

    public function testTheHeadRunChecksOutTheHeadAndLaysNothingOverIt(): void
    {
        $head = $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $this->whenTheJobsLeave(["passed\n", "passed\n"]);

        $command = [...$this->ran[1]->command];
        self::assertSame(['intakt-regression', '/input/mirror', '/input/handback.bundle', 'task', $head, '-', '0', 'composer install --no-interaction --no-progress', ...new PhpGate()->testSteps(new Paths('tests/FooTest.php'))], array_slice($command, 3));
    }

    public function testBothJobsReadTheScriptAndReachOnlyTheGatesRegistries(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $this->whenTheJobsLeave(["passed\n", "passed\n"]);

        foreach ($this->ran as $spec) {
            self::assertSame((string) file_get_contents(self::SCRIPT), [...$spec->command][2]);
            self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], [...$spec->egress->hosts]);
        }
    }

    public function testAHeadThatAddsOrChangesNoTestYieldsAResultSayingSoAndStartsNoJob(): void
    {
        $this->history->commitFile('src/Foo.php', "<?php\n", 'Fix the bug');

        $runs = $this->whenTheJobsLeave([]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertTrue($runs->foundNoTest());
        self::assertNull($runs->base);
        self::assertNull($runs->head);
        self::assertSame([], $this->ran);
    }

    public function testAMalformedResultFileIsReadAsNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["passed\nextra\n", "errored 0\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
        self::assertSame(RegressionRun::NoResult, $runs->head);
    }

    public function testAFailedRunWithAStatusOrAnErroredRunWithoutOneIsReadAsNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["failed 1\n", "errored\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
        self::assertSame(RegressionRun::NoResult, $runs->head);
    }

    public function testATestThatReachedNoVerdictIsErroredAndNotFailed(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["errored 3\n", "errored 255\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::Errored, $runs->base);
        self::assertSame(RegressionRun::Errored, $runs->head);
    }

    public function testAnErroredRunWithTheFailedStatusIsReadAsNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["errored 1\n", "errored 2\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
        self::assertSame(RegressionRun::Errored, $runs->head);
    }

    public function testAnErroredRunWithAStatusAboveTwoHundredFiftyFiveIsReadAsNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["errored 256\n", "errored 999\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
        self::assertSame(RegressionRun::NoResult, $runs->head);
    }

    public function testARunThatCouldNotInstallOrApplyLeavesNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->whenTheJobsLeave(["not-installed\n", "not-applied\n"]);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
        self::assertSame(RegressionRun::NoResult, $runs->head);
    }

    public function testARunThatRanOutOfTimeIsTimedOutWhateverItsOutputSays(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->runWith($this->runner(new JobResult(new JobEnd(JobCause::TimedOut, 137), OutputFiles::of(['regression-result' => "passed\n"]))));

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::TimedOut, $runs->base);
    }

    public function testARunKilledAtItsMemoryLimitLeavesNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');

        $runs = $this->runWith($this->runner(new JobResult(new JobEnd(JobCause::MemoryKilled, 137), OutputFiles::of(['regression-result' => "passed\n"]))));

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->head);
    }

    public function testAnOutputTheRunnerRefusesLeavesNoResult(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::unlisted());

        $runs = $this->runWith($runner);

        self::assertInstanceOf(RegressionRuns::class, $runs);
        self::assertSame(RegressionRun::NoResult, $runs->base);
    }

    public function testAFailedHeadJobAfterABaseThatDidNotPassStillRaisesItsException(): void
    {
        $this->history->commitFile('tests/FooTest.php', "<?php\n", 'Add a test');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec): JobResult {
            if (str_starts_with($spec->id->value, 'regression-head-')) {
                throw JobRefused::alreadyRunning($spec->id);
            }

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(['regression-result' => "failed\n"]));
        });

        $this->expectException(JobRefused::class);

        $this->runWith($runner);
    }

    public function testARefusedHandBackStartsNoJob(): void
    {
        $this->history->commit('Build work');

        $outcome = $this->runWith($this->runner(new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([]))), $this->history->file('no bundle'));

        self::assertInstanceOf(RefusedHandBack::class, $outcome);
        self::assertSame([], $this->ran);
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    /**
     * @param list<string> $results
     */
    private function whenTheJobsLeave(array $results): RegressionRuns|RefusedHandBack
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use (&$results): JobResult {
            $this->ran[] = $spec;

            return new JobResult(new JobEnd(JobCause::Exited, 1), OutputFiles::of(['regression-result' => array_shift($results) ?? '']));
        });

        return $this->runWith($runner);
    }

    private function runner(JobResult $result): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($result): JobResult {
            $this->ran[] = $spec;

            return $result;
        });

        return $runner;
    }

    private function runWith(JobRunner $runner, ?string $bundle = null): RegressionRuns|RefusedHandBack
    {
        $git = new RepositoryGit(new HardenedGit());
        $gate = new PhpGate();
        $job = new CheckJob(new HandBack($this->history->mirror, 'task', $bundle ?? $this->history->bundle('main..task'), false, new JobCredentials()), 'job-image', null, new Paths());

        return new RunRegressionTests(
            new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks')),
            new RegressionTestFiles($git, $gate),
            $runner,
            $gate,
            new Filesystem(),
            self::SCRIPT,
        )->run($job);
    }
}
