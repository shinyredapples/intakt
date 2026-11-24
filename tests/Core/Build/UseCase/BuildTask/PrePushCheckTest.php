<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[CoversClass(PrePushCheck::class)]
#[UsesClass(HandBackFile::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(CheckJobOutcome::class)]
#[UsesClass(CheckVerdict::class)]
#[UsesClass(Paths::class)]
#[UsesClass(RegressionRuns::class)]
#[UsesClass(RegressionRun::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackBranchRefused::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBackRefusal::class)]
final class PrePushCheckTest extends TestCase
{
    private string $folder = '';

    public function testARefusedBranchBecomesTheFailureText(): void
    {
        $failure = $this->checkThrowing(HandBackBranchRefused::isMain('main'))->check($this->request(), 'BUNDLE', TaskKind::Task);

        self::assertSame('the task\'s branch "main" is main itself', $failure);
    }

    public function testAnUnexpectedInvalidArgumentExceptionFromTheCheckJobPassesThrough(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('a bug in the check job'));

        $this->checkThrowing(new InvalidArgumentException('a bug in the check job'))->check($this->request(), 'BUNDLE', TaskKind::Task);
    }

    public function testATaskThatIsNotABugRunsNoRegressionTests(): void
    {
        $regressionTests = $this->createMock(RegressionTests::class);
        $regressionTests->expects(self::never())->method('run');

        $failure = $this->passingCheck($regressionTests, new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Task);

        self::assertNull($failure);
    }

    public function testABugHeadWhoseNewTestFailsOnTheBaseAndPassesOnTheHeadPassesWithItsRuns(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame($runs, $result);
    }

    public function testABugHeadWhoseNewTestAlsoPassesOnTheBaseIsSentBackBecauseItProvesNothing(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Passed, RegressionRun::Passed);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(PrePushCheck::PASSES_ON_THE_BASE, $result);
    }

    public function testABugHeadWhoseNewTestPassesOnTheBaseAndFailsOnTheHeadIsSentBackBecauseItProvesNothing(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Passed, RegressionRun::Failed);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(PrePushCheck::PASSES_ON_THE_BASE, $result);
    }

    public function testABugHeadWithoutANewTestIsSentBack(): void
    {
        $result = $this->passingCheck(new NoRegressionTests(), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(PrePushCheck::NO_TEST, $result);
    }

    public function testABugHeadWhoseNewTestStillFailsOnTheHeadIsSentBackNamingBothResults(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Failed);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame('the regression test must fail on the base and pass on the head, but it failed on the base and failed on the head', $result);
    }

    public function testABugHeadWhoseBaseRunErroredIsSentBackNamingBothResults(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Errored, RegressionRun::Passed);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame('the regression test must fail on the base and pass on the head, but it errored on the base and passed on the head', $result);
    }

    public function testABugHeadWithATestButNoRunsIsSentBackNamingBothAsMissing(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), null, RegressionRun::TimedOut);

        $result = $this->passingCheck($this->regressionTestsGiving($runs), new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame('the regression test must fail on the base and pass on the head, but it gave no run on the base and timed-out on the head', $result);
    }

    public function testABugHeadWhoseRegressionJobIsRefusedIsSentBackWithTheRefusal(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn(new RefusedHandBack(HandBackRefusal::NotOnBranch));

        $result = $this->passingCheck($regressionTests, new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(HandBackRefusal::NotOnBranch->reason(), $result);
    }

    public function testABugHeadWhoseRegressionTestsCouldNotRunFailsThePrePushCheckWithFixedText(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willThrowException(HandBackNotChecked::in('mirror', 'it is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $result = $this->passingCheck($regressionTests, $logger)->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(PrePushCheck::NOT_RUN, $result);
    }

    public function testARegressionScriptThatCannotBeReadFailsThePrePushCheckWithFixedText(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willThrowException(new IOException('Failed to read file "docker/job/regression.sh"'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $result = $this->passingCheck($regressionTests, $logger)->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame(PrePushCheck::NOT_RUN, $result);
    }

    public function testABugHeadThatFailsTheCheckRunsNoRegressionTests(): void
    {
        $jobs = self::createStub(CheckJobs::class);
        $jobs->method('run')->willReturn(new CheckJobOutcome(CheckVerdict::Failed, 1));
        $regressionTests = $this->createMock(RegressionTests::class);
        $regressionTests->expects(self::never())->method('run');
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->folder.'/mirror');

        $failure = new PrePushCheck($jobs, new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs'), $regressionTests, new NullLogger())->check($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame('the check command failed (exit code 1)', $failure);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-pre-push-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function checkThrowing(Throwable $failure): PrePushCheck
    {
        $jobs = self::createStub(CheckJobs::class);
        $jobs->method('run')->willThrowException($failure);
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->folder.'/mirror');

        return new PrePushCheck($jobs, new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs'), new NoRegressionTests(), new NullLogger());
    }

    private function passingCheck(RegressionTests $regressionTests, LoggerInterface $logger): PrePushCheck
    {
        $jobs = self::createStub(CheckJobs::class);
        $jobs->method('run')->willReturn(new CheckJobOutcome(CheckVerdict::Passed));
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->folder.'/mirror');

        return new PrePushCheck($jobs, new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs'), $regressionTests, $logger);
    }

    private function regressionTestsGiving(RegressionRuns $runs): RegressionTests
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn($runs);

        return $regressionTests;
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', 7, $this->folder.'/remote', 'main', $this->folder.'/clone', 'intakt-job-alpha:1');
    }
}
