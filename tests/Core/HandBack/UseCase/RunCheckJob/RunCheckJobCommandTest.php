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
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
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
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJobCommand;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJobInput;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunCheckJobCommand::class)]
#[CoversClass(RunCheckJobInput::class)]
#[UsesClass(RunCheckJob::class)]
#[UsesClass(LostContentCheck::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(Paths::class)]
#[UsesClass(CheckJobOutcome::class)]
#[UsesClass(CheckVerdict::class)]
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
#[UsesClass(HandBackNotChecked::class)]
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
#[UsesClass(DockerEngineFailed::class)]
#[UsesClass(HandBackBranchRefused::class)]
#[UsesClass(JobRefused::class)]
final class RunCheckJobCommandTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/check.sh';

    private BuildHistory $history;

    public function testAGreenCheckPrintsTheEndOfItsLogAndPassed(): void
    {
        $this->history->commit('Build work');

        $tester = $this->executed($this->runnerLeaving(['check-result' => "passed\n", 'check.log' => "\$ bin/check --fast\n<info>ok</info>\n"]), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("\$ bin/check --fast\n<info>ok</info>\npassed\n", $tester->getDisplay());
    }

    public function testARedCheckPrintsTheReasonAndTheExitCodeAndFails(): void
    {
        $this->history->commit('Build work');

        $tester = $this->executed($this->runnerLeaving(['check-result' => "failed 2\n", 'check.log' => "1 test failed\n"]), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith("1 test failed\n", $tester->getDisplay());
        self::assertStringContainsString('failed: '.CheckVerdict::Failed->reason().' (exit code 2)', $this->flat($tester));
    }

    public function testOnlyTheLastFortyLinesOfTheLogArePrinted(): void
    {
        $this->history->commit('Build work');
        $log = implode('', array_map(static fn (int $line): string => 'line '.$line."\n", range(1, 50)));

        $tester = $this->executed($this->runnerLeaving(['check-result' => "passed\n", 'check.log' => $log]), []);

        self::assertStringStartsWith("line 11\n", $tester->getDisplay());
    }

    public function testALossOfContentNamesTheFileAndFails(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('docs/guide.md', str_repeat("a line of the guide\n", 120), 'Add the guide');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->commitFile('docs/guide.md', "1\n", 'Shorten the guide');

        $tester = $this->executed($this->runnerLeaving([]), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith("lost docs/guide.md: 120 lines to 1\n", $tester->getDisplay());
        self::assertStringContainsString('failed: '.CheckVerdict::LostContent->reason(), $this->flat($tester));
    }

    public function testAPathGivenAsRewrittenMayLoseItsContent(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('docs/guide.md', str_repeat("a line of the guide\n", 120), 'Add the guide');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->commitFile('docs/guide.md', "1\n", 'Rewrite the guide');

        $tester = $this->executed($this->runnerLeaving(['check-result' => "passed\n"]), ['--rewritten' => ['docs/other.md', 'docs/guide.md']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheConfiguredCheckCommandReachesTheJob(): void
    {
        $this->history->commit('Build work');
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::once())->method('run')
            ->with(self::callback(static fn (JobSpec $spec): bool => ['make check'] === array_slice($spec->command, 8)))
            ->willReturn(new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['check-result' => "passed\n"])));

        $tester = $this->executed($runner, ['--check-command' => 'make check']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testARefusedBundlePrintsTheReasonAndFails(): void
    {
        $this->history->commit('Build work');

        $tester = $this->executed($this->runnerLeaving([]), ['bundle' => $this->history->bundle('main', 'task')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('refused: '.HandBackRefusal::TouchesMain->reason(), $this->flat($tester));
    }

    public function testAnEngineThatCannotBeReachedIsPrintedAndFails(): void
    {
        $this->history->commit('Build work');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(DockerEngineFailed::noSocket('/var/run/docker.sock'));

        $tester = $this->executed($runner, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('there is no socket at /var/run/docker.sock', $this->flat($tester));
    }

    public function testABranchThatIsMainIsStillPrintedAndFails(): void
    {
        $this->history->commit('Build work');

        $tester = $this->executed($this->runnerLeaving([]), ['branch' => 'main']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the task\'s branch "main" is main itself', $this->flat($tester));
    }

    public function testAJobTheRunnerRefusesIsStillPrintedAndFails(): void
    {
        $this->history->commit('Build work');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobRefused::alreadyRunning(new JobId('check-demo')));

        $tester = $this->executed($runner, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The job "check-demo" is already running.', $this->flat($tester));
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheCommand(): void
    {
        $this->history->commit('Build work');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(new InvalidArgumentException('a bug in the runner'));

        $this->expectExceptionObject(new InvalidArgumentException('a bug in the runner'));

        $this->executed($runner, []);
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
     * @param array<string, string> $files
     */
    private function runnerLeaving(array $files): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturn(new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput($files)));

        return $runner;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function executed(JobRunner $runner, array $options): CommandTester
    {
        $git = new RepositoryGit(new HardenedGit());
        $checks = new RunCheckJob(
            new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks')),
            new LostContentCheck($git),
            $runner,
            new PhpGate(),
            new Filesystem(),
            self::SCRIPT,
        );
        $application = new Application();
        $application->addCommand(new RunCheckJobCommand($checks));
        $tester = new CommandTester($application->find('intakt:check:run'));
        $tester->execute([
            'repository' => $this->history->mirror,
            'branch' => 'task',
            'bundle' => $options['bundle'] ?? $this->history->bundle('main..task'),
            'image' => 'job-image',
            ...array_diff_key($options, ['bundle' => true]),
        ]);

        return $tester;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
