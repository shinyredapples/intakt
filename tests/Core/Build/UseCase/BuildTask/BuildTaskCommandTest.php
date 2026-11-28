<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\BuildStepNotRecorded;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskCommand;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskInput;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackCheck;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Build\UseCase\BuildTask\PullRequestTextCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteRefused;
use Intakt\Core\Build\UseCase\BuildTask\PushStep;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackChecks;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\MissingEvidenceItems;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Build\ParkedTaskTable;
use Intakt\Tests\Core\Build\ScriptedJobRunner;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(BuildTaskCommand::class)]
#[UsesClass(BuildTaskInput::class)]
#[UsesClass(BuildTask::class)]
#[UsesClass(BuildTaskOutcome::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(BuildStepNotRecorded::class)]
#[UsesClass(BuildCheckoutFailed::class)]
#[UsesClass(HandBackCheck::class)]
#[UsesClass(HandBackFile::class)]
#[UsesClass(PrePushCheck::class)]
#[UsesClass(PullRequestTextCheck::class)]
#[UsesClass(PullRequestTextTask::class)]
#[UsesClass(PullRequestTextOutcome::class)]
#[UsesClass(MissingEvidenceItems::class)]
#[UsesClass(PullRequestTextVerdict::class)]
#[UsesClass(PushStep::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(PushRemoteCheck::class)]
#[UsesClass(PushRemoteRefused::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(RefusedPush::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(OpenedChange::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(CheckJobOutcome::class)]
#[UsesClass(CheckVerdict::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(Issues::class)]
#[UsesClass(ScriptedJobRunner::class)]
#[UsesClass(RunBuildJob::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildJobOutcome::class)]
#[UsesClass(BuildVerdict::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class BuildTaskCommandTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private string $folder = '';

    /** @var array<string, string> */
    private array $options = [];

    private AcceptedHandBack|RefusedHandBack $checked;

    private CheckJobOutcome|RefusedHandBack $checkedBeforePush;

    private PushedBranch|RefusedPush $pushed;

    private ?string $configuredRepository = null;

    public function testItPrintsEachPassedStepAndTheEscapedTitle(): void
    {
        $tester = $this->execute(new InMemoryLedger(), self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("task passed\nmirror passed\nclone passed\nbuild_job passed\nhand_back_check passed\npre_push_check passed\npush passed\ntitle Task 7", $tester->getDisplay());
    }

    public function testItExitsWithFailureOnARemoteOutsideTheConfiguredRepositoryAndReportsNoStepAsPassed(): void
    {
        $this->configuredRepository = 'acme/alpha';
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options, '--remote' => 'https://github.com/acme/beta']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the remote "https://github.com/acme/beta" is not the configured GitHub', $tester->getDisplay());
        self::assertStringNotContainsString('passed', $tester->getDisplay());
        self::assertSame([], $ledger->payloads());
    }

    public function testItExitsWithFailureWhenTheRemoteRefusesThePushAndPrintsItsReason(): void
    {
        $this->pushed = new RefusedPush('the remote refused the push of feat/task');
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("pre_push_check passed\ntitle Task 7", $tester->getDisplay());
        self::assertStringContainsString('push failed: the remote refused the push of feat/task', $tester->getDisplay());
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testItExitsWithFailureWhenThePrePushCheckFailsAndPrintsNothingOfItsLog(): void
    {
        $this->checkedBeforePush = new CheckJobOutcome(CheckVerdict::Failed, 2, log: "PLANT-log\n");
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("hand_back_check passed\ntitle Task 7", $tester->getDisplay());
        self::assertStringContainsString('pre_push_check failed: the check command failed (exit code 2)', $tester->getDisplay());
        self::assertStringNotContainsString('PLANT', $tester->getDisplay());
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testItExitsWithFailureWhenThePrePushCheckRefusesTheHandBack(): void
    {
        $this->checkedBeforePush = new RefusedHandBack(HandBackRefusal::Unreadable);
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('pre_push_check failed: '.HandBackRefusal::Unreadable->reason(), $tester->getDisplay());
        self::assertSame(HandBackRefusal::Unreadable->reason(), $ledger->payloads()[5]['failure']);
    }

    public function testItExitsWithFailureWhenTheHandBackCheckRefusesTheBundle(): void
    {
        $this->checked = new RefusedHandBack(HandBackRefusal::TouchesMain);
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("build_job passed\ntitle Task 7", $tester->getDisplay());
        self::assertStringContainsString('hand_back_check failed: '.HandBackRefusal::TouchesMain->reason(), $tester->getDisplay());
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testItExitsWithFailureWhenTheBuildJobHandsBackNoCommit(): void
    {
        new Filesystem()->remove($this->folder.'/output/'.RunBuildJob::BUNDLE);
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("clone passed\ntitle Task 7", $tester->getDisplay());
        self::assertStringContainsString('build_job failed: the build made no commit on the task\'s branch', $tester->getDisplay());
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testItReplacesEachControlCharacterOfTheTitleSoItCannotDriveTheTerminal(): void
    {
        $display = $this->displayOfTitle("Evil \e[2J\e]0;pwned\x07 <error>x</error> \x7F\u{80}\u{9B}31m\ttab\nline");

        self::assertStringContainsString("title Evil ?[2J?]0;pwned? <error>x</error> ???31m?tab?line\n", $display);
        self::assertDoesNotMatchRegularExpression('/[^\P{Cc}\n]/u', $display);
    }

    public function testItKeepsEveryOtherCharacterOfTheTitle(): void
    {
        $title = "Gr\u{FC}\u{DF}e \u{A0}\u{2713} ~ \u{1F600}";

        self::assertStringContainsString('title '.$title."\n", $this->displayOfTitle($title));
    }

    public function testItKeepsOnlyThePrintableAsciiOfATitleThatIsNotUtf8(): void
    {
        $display = $this->displayOfTitle("Evil \xFF\x9B31m \e[2J~");

        self::assertStringContainsString("title Evil ??31m ?[2J~\n", $display);
    }

    public function testItExitsWithFailureAtTheFailingStepAndRunsNoLaterOne(): void
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('updateMirror')->willThrowException(new BuildCheckoutFailed('git failed after 3 attempts'));
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, $checkout, ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('task passed', $tester->getDisplay());
        self::assertStringNotContainsString('mirror passed', $tester->getDisplay());
        self::assertStringContainsString('mirror failed: git failed after 3 attempts', $tester->getDisplay());
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
    }

    public function testItReportsAStepTheLedgerDidNotRecord(): void
    {
        $tester = $this->execute(new InMemoryLedger(0), self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...$this->options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not record the task step', $tester->getDisplay());
    }

    #[DataProvider('refusedInputs')]
    public function testItRefusesABadInputWithoutRunningAStep(string $issue, string $option, string $message): void
    {
        $ledger = new InMemoryLedger();
        $options = array_filter($this->options, static fn (string $name): bool => $name !== $option, ARRAY_FILTER_USE_KEY);

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => $issue, ...$options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(0, $ledger->count());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'text' => ['seven', '', 'is not an issue number'];
        yield 'zero' => ['0', '', 'is not an issue number'];
        yield 'a number too long' => ['1234567890', '', 'is not an issue number'];
        yield 'no remote' => ['7', '--remote', 'The --remote option is required.'];
        yield 'no branch' => ['7', '--branch', 'The --branch option is required.'];
        yield 'no directory' => ['7', '--directory', 'The --directory option is required.'];
        yield 'no image' => ['7', '--image', 'The --image option is required.'];
    }

    protected function setUp(): void
    {
        $this->pushed = new PushedBranch(str_repeat('b', 40), new ChangeId('https://github.com/acme/alpha/pull/1'));
        $this->checked = new AcceptedHandBack(null, str_repeat('a', 40), 0);
        $this->checkedBeforePush = new CheckJobOutcome(CheckVerdict::Passed, log: "ok\n");
        $this->folder = sys_get_temp_dir().'/intakt-build-task-command-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/clone');
        new Filesystem()->dumpFile($this->folder.'/output/'.RunBuildJob::BUNDLE, 'BUNDLE');
        new Filesystem()->dumpFile($this->folder.'/output/'.RunBuildJob::PULL_REQUEST_TEXT, "feat: task 7\n");
        $this->options = ['--remote' => 'remote', '--branch' => 'feat/task', '--directory' => $this->folder.'/clone', '--image' => 'intakt-job-alpha:1'];
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function displayOfTitle(string $title): string
    {
        $listed = Issues::task(7);
        $task = new ListedIssue(7, $title, $listed->body, $listed->labels, $listed->author, $listed->updatedAt, $listed->milestoneDue, false);
        $build = $this->buildTask($this->listing($task), self::createStub(BuildCheckout::class), new InMemoryLedger());

        return $this->tester($build, ['project' => 'alpha', 'issue' => '7', ...$this->options])->getDisplay();
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(InMemoryLedger $ledger, BuildCheckout $checkout, array $input): CommandTester
    {
        return $this->tester($this->buildTask($this->listing(Issues::task(7)), $checkout, $ledger), $input);
    }

    private function listing(ListedIssue $task): IssueReads
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn($task);

        return $reads;
    }

    private function buildTask(IssueReads $issues, BuildCheckout $checkout, InMemoryLedger $ledger): BuildTask
    {
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturn(new TierChoice(Tier::Standard, TierReason::Default));
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
        $builds = new RunBuildJob($tiers, new BuilderPrompt(new Fence(), new PromptCut(new Fence())), new ScriptedJobRunner($this->folder.'/output'), new PhpGate(), new GatesAtOnce(12, 1), 1500, new Filesystem(), self::SCRIPT, $this->folder.'/prompts', new RecordedBuildStops(), new BuildLimits(1, 1, 1, 1));
        $checks = self::createStub(HandBackChecks::class);
        $checks->method('check')->willReturn($this->checked);
        $checkJobs = self::createStub(CheckJobs::class);
        $checkJobs->method('run')->willReturn($this->checkedBeforePush);
        $files = new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs');
        $branches = self::createStub(BranchPush::class);
        $branches->method('push')->willReturn($this->pushed);

        // The parked-task table alone, because the parked run's output is proven on the install's own database in BuildTaskParksOnSqliteTest.
        $parked = new ParkedTasks(ParkedTaskTable::inMemory(), $ledger, self::createStub(ManagerRegistry::class), new MockClock());

        $repositories = self::createStub(ConfiguredRepositories::class);
        $repositories->method('nameOf')->willReturn($this->configuredRepository);

        $texts = self::createStub(PullRequestTextRound::class);
        $texts->method('route')->willReturn(new PullRequestTextOutcome(PullRequestTextVerdict::Passed, 0, new MissingEvidenceItems(), 0));
        // A folder that exists, because the text round resolves the mirror's path and a stubbed checkout names none.
        $mirror = self::createStub(BuildCheckout::class);
        $mirror->method('mirrorOf')->willReturn($this->folder);

        return new BuildTask($issues, $checkout, $routing, $builds, new HandBackCheck($checkout, $checks, $files), new PrePushCheck($checkJobs, $files, new NoRegressionTests(), new NullLogger()), new PullRequestTextCheck($mirror, $texts), new PushStep($branches, $checkout), $parked, $ledger, new PushRemoteCheck($repositories), new LockFactory(new InMemoryStore()));
    }

    /**
     * @param array<string, string> $input
     */
    private function tester(BuildTask $build, array $input): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BuildTaskCommand($build));
        $tester = new CommandTester($application->find('intakt:build:task'));
        $tester->execute($input);

        return $tester;
    }
}
