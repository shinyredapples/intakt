<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Build\UseCase\BuildTask\PrePushResult;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
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
#[UsesClass(PrePushResult::class)]
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
final class PrePushCheckTest extends TestCase
{
    private string $folder = '';

    public function testARefusedBranchBecomesTheFailureText(): void
    {
        $failure = $this->checkThrowing(HandBackBranchRefused::isMain('main'))->resultOf($this->request(), 'BUNDLE', TaskKind::Task)->failure;

        self::assertSame('the task\'s branch "main" is main itself', $failure);
    }

    public function testAnUnexpectedInvalidArgumentExceptionFromTheCheckJobPassesThrough(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('a bug in the check job'));

        $this->checkThrowing(new InvalidArgumentException('a bug in the check job'))->resultOf($this->request(), 'BUNDLE', TaskKind::Task);
    }

    public function testATaskThatIsNotABugRunsNoRegressionTests(): void
    {
        $regressionTests = $this->createMock(RegressionTests::class);
        $regressionTests->expects(self::never())->method('run');

        $failure = $this->passingCheck($regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Task)->failure;

        self::assertNull($failure);
    }

    public function testABugHeadWhoseNewTestFailsOnTheBaseAndPassesOnTheHeadPassesThePrePushCheck(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'The regression tests of a bug head ran.',
            ['project' => 'alpha', 'tests' => ['tests/FooTest.php'], 'base' => 'failed', 'head' => 'passed'],
        );

        $failure = $this->passingCheck($regressionTests, $logger)->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertNull($failure);
    }

    public function testABugHeadWhoseTestProvesTheFixHandsBackItsRegressionRuns(): void
    {
        $runs = new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn($runs);

        $result = $this->passingCheck($regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Bug);

        self::assertSame($runs, $result->runs);
    }

    public function testATaskThatIsNotABugHandsBackNoRuns(): void
    {
        $result = $this->passingCheck(self::createStub(RegressionTests::class), new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Task);

        self::assertNull($result->runs);
    }

    public function testABugHeadWhoseNewTestAlsoPassesOnTheBaseFailsThePrePushCheckWithTheReason(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Passed, RegressionRun::Passed));

        $failure = $this->passingCheck($regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertSame('the new test of a bug also passes on the base, so it proves nothing', $failure);
    }

    public function testABugHeadWithoutANewTestFailsThePrePushCheckWithTheReason(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn(RegressionRuns::noTest());

        $failure = $this->passingCheck($regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertSame('the head of a bug adds no regression test', $failure);
    }

    public function testABugHeadWhoseTestOnlyErroredOnTheBaseStillPassesThePrePushCheck(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Errored, RegressionRun::Passed));

        $failure = $this->passingCheck($regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertNull($failure);
    }

    public function testABugWhoseRegressionTestsCouldNotRunStillPassesThePrePushCheck(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willThrowException(HandBackNotChecked::in('mirror', 'it is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $failure = $this->passingCheck($regressionTests, $logger)->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertNull($failure);
    }

    public function testARegressionScriptThatCannotBeReadDoesNotChangeThePrePushVerdict(): void
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willThrowException(new IOException('Failed to read file "docker/job/regression.sh"'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $failure = $this->passingCheck($regressionTests, $logger)->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

        self::assertNull($failure);
    }

    public function testABugHeadThatFailsTheCheckRunsNoRegressionTests(): void
    {
        $jobs = self::createStub(CheckJobs::class);
        $jobs->method('run')->willReturn(new CheckJobOutcome(CheckVerdict::Failed, 1));
        $regressionTests = $this->createMock(RegressionTests::class);
        $regressionTests->expects(self::never())->method('run');
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('mirrorOf')->willReturn($this->folder.'/mirror');

        $failure = new PrePushCheck($jobs, new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs'), $regressionTests, new NullLogger())->resultOf($this->request(), 'BUNDLE', TaskKind::Bug)->failure;

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

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', 7, $this->folder.'/remote', 'main', $this->folder.'/clone', 'intakt-job-alpha:1');
    }
}
