<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\BugPart;
use Intakt\Core\Build\Domain\BugReport;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Build\Domain\RegressionEvidence;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\BuildStepNotRecorded;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackCheck;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Build\UseCase\BuildTask\PrePushResult;
use Intakt\Core\Build\UseCase\BuildTask\PullRequestTextCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteRefused;
use Intakt\Core\Build\UseCase\BuildTask\PushStep;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
use Intakt\Core\Git\Domain\CommitObject;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\Infrastructure\GitBuildCheckout;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\PushBranch;
use Intakt\Core\Git\UseCase\PushBranch\PushRecords;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\Git\UseCase\TakeCheckedHead\TakeCheckedHead;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\FailedPullRequestText;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\MissingEvidenceItems;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextRoute;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\HiddenBlockOpeners;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\PullRequestTextRoutes;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\RoutePullRequestText;
use Intakt\Core\HandBack\UseCase\RunCheckJob\ChangedFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckDependencies;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFiles;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyJobs;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LostContentCheck;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJob;
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
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Build\ScriptedJobRunner;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\ProgramOnPath;
use Intakt\Tests\Core\Git\UseCase\PushBranch\PushScenario;
use Intakt\Tests\Core\Git\UseCase\PushBranch\RecordingChangeOpening;
use Intakt\Tests\Core\HandBack\CheckJobsFor;
use Intakt\Tests\Core\HandBack\FailingLockStore;
use Intakt\Tests\Core\HandBack\RecordingLedger;
use Intakt\Tests\Core\HandBack\UnreadableLedger;
use Intakt\Tests\Core\HandBack\UnreleasableLockStore;
use Intakt\Tests\Core\Job\OutputFiles;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

#[CoversClass(BuildTask::class)]
#[CoversClass(BuildTaskOutcome::class)]
#[CoversClass(BuildTaskRequest::class)]
#[CoversClass(BuildRun::class)]
#[CoversClass(BuildStepNotRecorded::class)]
#[CoversClass(GitBuildCheckout::class)]
#[CoversClass(BuildCheckoutFailed::class)]
#[CoversClass(HandBackCheck::class)]
#[CoversClass(HandBackFile::class)]
#[CoversClass(PrePushCheck::class)]
#[CoversClass(PullRequestTextCheck::class)]
#[UsesClass(RoutePullRequestText::class)]
#[UsesClass(PullRequestTextRoutes::class)]
#[UsesClass(PullRequestTextTask::class)]
#[UsesClass(FailedPullRequestText::class)]
#[UsesClass(PullRequestTextOutcome::class)]
#[UsesClass(MissingEvidenceItems::class)]
#[UsesClass(PullRequestTextRoute::class)]
#[UsesClass(PullRequestTextVerdict::class)]
#[UsesClass(PullRequestTextRule::class)]
#[UsesClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(HiddenBlockOpeners::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
#[CoversClass(PushStep::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(PushRemoteCheck::class)]
#[UsesClass(PushRemoteRefused::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(TaskNotParked::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(QuestionRefused::class)]
#[UsesClass(PushBranch::class)]
#[UsesClass(PushRecords::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(BranchPushRequest::class)]
#[UsesClass(PushedBranch::class)]
#[UsesClass(RefusedPush::class)]
#[UsesClass(ChangeText::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(OpenedChange::class)]
#[UsesClass(ProposedChange::class)]
#[UsesClass(RunCheckJob::class)]
#[UsesClass(LostContentCheck::class)]
#[UsesClass(CheckDependencies::class)]
#[UsesClass(CheckedLockFiles::class)]
#[UsesClass(DependencyJobs::class)]
#[UsesClass(DependencySnapshots::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(CheckJobOutcome::class)]
#[UsesClass(CheckVerdict::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(TakeCheckedHead::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(Issues::class)]
#[UsesClass(ScriptedJobRunner::class)]
#[UsesClass(RunBuildJob::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildJobOutcome::class)]
#[UsesClass(BuildVerdict::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(TaskKind::class)]
#[UsesClass(BugReport::class)]
#[UsesClass(RegressionEvidence::class)]
#[UsesClass(PrePushResult::class)]
#[UsesClass(BugPart::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(BuildRoutingNotConfigured::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class BuildTaskTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private const string CHECK_SCRIPT = __DIR__.'/../../../../../docker/job/check.sh';

    private const string BUG_BODY = "**When:** a\n**Today:** b\n**Expected:** c\n**Must keep working:** d";

    private const string IMAGE = 'intakt-job-alpha:1';

    // Because CI runs as a user without a git identity, and git refuses a commit without one.
    private const array IDENTITY = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false'];

    private string $scanner = 'gitleaks';

    private ?RegressionTests $regressionTests = null;

    private string $mirrors;

    private string $clone;

    private string $output;

    private TemporaryRepository $remote;
    private ?string $configuredRepository = null;

    private ?ProgramOnPath $programs = null;

    private BuildRouting $routing;

    private TierResolver $tiers;

    private JobRunner $runner;

    private LockFactory $locks;

    private JobRunner $checkRunner;

    private ?JobSpec $checked = null;

    private Filesystem $handBackFiles;

    private RecordingChangeOpening $changes;

    // A ledger that reads back for the text round, because InMemoryLedger reads nothing and the round counts the task's earlier lines.
    private ?RecordingLedger $textLedger = null;

    private ?LockFactory $textLocks = null;

    private RecordedBuildStops $stops;

    public function testARemoteNamingAnotherGitHubRepositoryThanTheConfiguredOneIsRefusedBeforeAnythingIsRecordedPushedOrAskedOfTheForge(): void
    {
        $this->configuredRepository = 'acme/alpha';
        $ledger = new InMemoryLedger();
        $issues = $this->createMock(IssueReads::class);
        $issues->expects(self::never())->method('readOpen');
        $issues->expects(self::never())->method('listOpen');
        $this->changes->checking = static fn () => self::fail('the forge was asked about the change');

        try {
            (void) $this->buildTask($issues, $this->checkout(), $ledger)->run($this->request(7, 'https://github.com/acme/beta.git'));
            self::fail('the remote was not refused');
        } catch (PushRemoteRefused $refused) {
            self::assertStringContainsString('"https://github.com/acme/beta.git"', $refused->getMessage());
            self::assertStringContainsString('"acme/alpha"', $refused->getMessage());
        }

        self::assertSame([], $ledger->payloads());
        self::assertSame(0, $this->changes->calls);
        self::assertNull($this->remoteTip());
    }

    public function testARemoteNamingTheConfiguredRepositoryWithAGitSuffixAndOtherLetterCaseIsAccepted(): void
    {
        $this->configuredRepository = 'acme/alpha';
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [])->run($this->request(7, 'https://GitHub.com/ACME/Alpha.git'));

        self::assertSame('project "alpha" has no open issue 7', $outcome->failure);
        self::assertSame(['task'], array_column($this->stepsIn($ledger), 'step'));
    }

    public function testATaskWhoseStepsAllPassEndsWithTheBranchPushedSignedFromTheCheckedHead(): void
    {
        $this->runner = $this->committingRunner();
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertFalse($outcome->failed());
        self::assertSame(BuildStep::Push, $outcome->last);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone, BuildStep::BuildJob, BuildStep::HandBackCheck, BuildStep::PrePushCheck, BuildStep::Push], $outcome->passedSteps());
        self::assertSame('Task 7', $outcome->task?->title);
        self::assertSame(['task', 'mirror', 'clone', 'build_job', 'hand_back_check', 'pre_push_check', 'push'], array_column($this->stepsIn($ledger), 'step'));
        self::assertSame(array_fill(0, 7, 'passed'), array_column($this->stepsIn($ledger), 'outcome'));
        self::assertSame('7', $ledger->payloads()[0]['task']);
        self::assertNull($this->stepsIn($ledger)[6]['failure']);
        self::assertSame([LedgerEventKind::PullRequestTextPassed, LedgerEventKind::BuildStepEnded, LedgerEventKind::PushIntended, LedgerEventKind::BranchPushed, LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened, LedgerEventKind::BuildStepEnded], array_slice($ledger->kinds(), 5));
        self::assertFileExists($this->clone.'/a.txt');
        self::assertStringEqualsFile($this->clone.'/b.txt', 'two');
        self::assertSame('', $this->inClone('status', '--porcelain'));
        self::assertSame("Build task 7\n", $this->inClone('log', '-1', '--format=%s', 'feat/task'));
        self::assertSame("commit\n", $this->git(['--git-dir='.new Mirrors($this->mirrors)->pathOf('alpha'), 'cat-file', '-t', trim($this->inClone('rev-parse', 'feat/task'))], $this->mirrors));
        self::assertSame("Build task 7\n", $this->git(['log', '-1', '--format=%s', 'feat/task'], $this->remote->path));
        self::assertStringContainsString("\ngpgsig ", $this->git(['cat-file', 'commit', 'feat/task'], $this->remote->path));
        self::assertSame(1, $this->changes->opened);
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    #[TestWith([BuildStopCause::NoProgress])]
    #[TestWith([BuildStopCause::RunawayCeiling])]
    #[TestWith([BuildStopCause::PerTaskTotal])]
    public function testABuildStoppedWhileItRanHandsBackItsCommitsThroughTheHandBackAndPrePushChecksToThePush(BuildStopCause $cause): void
    {
        $stopped = new BuildJob('alpha', $this->clone, 'feat/task', new BuildBrief('x', new Decisions(), null), Tier::Standard, self::IMAGE);
        $this->runner = $this->runnerAfterWhich($this->committingRunner(), fn () => $this->stops->record($stopped, $cause));
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone, BuildStep::BuildJob, BuildStep::HandBackCheck, BuildStep::PrePushCheck, BuildStep::Push], $outcome->passedSteps());
        self::assertSame("Build task 7\n", $this->git(['log', '-1', '--format=%s', 'feat/task'], $this->remote->path));
        self::assertNull($this->stops->causeFor($stopped), 'the build job did not read the recorded cause');
    }

    public function testThePullRequestTextsFirstLineIsTheTitleAndWhatFollowsTheBlankLineTheBody(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\nThe body.\n\nEvidence: tests pass.\n");

        (void) $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(['feat: task 7', "The body.\n\nEvidence: tests pass."], [$this->changes->text?->title, $this->changes->text?->body]);
    }

    public function testAFirstPullRequestTextMissEndsAtThePrePushCheckSentBackWithTheMissingItemsAndKeepsTheCommitsUnpushed(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red, and the word zebracorn\n");
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the pull request text is sent back to the builder: the pull request text does not reference every top-level Evidence item of the task; missing: E2', $outcome->failure);
        self::assertSame($outcome->failure, $this->stepsIn($ledger)[5]['failure']);
        self::assertContains(LedgerEventKind::PullRequestTextSentBack, $ledger->kinds());
        self::assertStringNotContainsString('zebracorn', (string) json_encode($ledger->payloads()));
        self::assertSame("Build task 7\n", $this->inClone('log', '-1', '--format=%s', 'feat/task'));
        self::assertNull($this->remoteTip());
        self::assertSame(0, $this->changes->calls);
    }

    public function testASecondPullRequestTextMissInARowGoesOnToThePushAndTheReviewers(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red\n");
        $this->textLedger = new RecordingLedger();
        $first = $this->taskOf(new InMemoryLedger(), [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));
        new Filesystem()->remove($this->clone);

        $outcome = $this->taskOf(new InMemoryLedger(), [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $first->last);
        self::assertFalse($outcome->failed(), (string) $outcome->failure);
        self::assertSame(BuildStep::Push, $outcome->last);
        self::assertSame([LedgerEventKind::PullRequestTextSentBack, LedgerEventKind::PullRequestTextToReviewers], $this->textLedger->kinds());
        self::assertSame("Build task 7\n", $this->git(['log', '-1', '--format=%s', 'feat/task'], $this->remote->path));
        self::assertSame(1, $this->changes->opened);
    }

    public function testALockStoreThatFailsInTheTextRoundEndsAtThePrePushCheckWithFixedTextAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red\n");
        $this->textLocks = new LockFactory(new FailingLockStore());
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertStringStartsWith('the pull request text round was not recorded, because its lock failed: Failed to acquire the "intakt-pull-request-text-', (string) $outcome->failure);
        self::assertSame($outcome->failure, $this->stepsIn($ledger)[5]['failure']);
        self::assertNull($this->remoteTip());
    }

    public function testATextRoundLockThatCannotBeReleasedStillSendsTheFirstMissBackWithAStepRow(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red\n");
        $this->textLocks = new LockFactory(new UnreleasableLockStore());
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertStringStartsWith('the pull request text is sent back to the builder: ', (string) $outcome->failure);
        self::assertSame($outcome->failure, $this->stepsIn($ledger)[5]['failure']);
        self::assertNull($this->remoteTip());
    }

    public function testALedgerThatCannotBeReadInTheTextRoundEndsAtThePrePushCheckWithFixedTextAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red\n");
        $this->textLedger = new RecordingLedger(onRead: static fn () => throw new UnreadableLedger('the ledger is unreachable'));

        $outcome = $this->taskOf(new InMemoryLedger(), [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the pull request text round was not recorded, because the ledger could not be read: the ledger is unreachable', $outcome->failure);
        self::assertNull($this->remoteTip());
    }

    public function testAPullRequestBodyOverTheCapEndsAtThePrePushCheckSentBack(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n".str_repeat('a', 4001));
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the pull request text is sent back to the builder: the pull request body is over the cap', $outcome->failure);
        self::assertNull($this->remoteTip());
    }

    public function testAFailedCheckJobRecordsNoPullRequestTextLineSoItsMissDoesNotCount(): void
    {
        $this->runner = $this->committingRunner(text: "feat: task 7\n\n- E1: red\n");
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "failed 2\n", RunCheckJob::LOG => "tests failed\n"]);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [$this->taskWithEvidence()])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame([], array_filter($ledger->kinds(), static fn (LedgerEventKind $kind): bool => str_starts_with($kind->value, 'pull_request_text_')));
    }

    public function testAPullRequestTextWithoutATitleEndsAtThePushStepAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner(text: "\n\nThe body.\n");
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Push, $outcome->last);
        self::assertSame('the pull request text has no title on its first line', $outcome->failure);
        self::assertSame('the pull request text has no title on its first line', $this->stepsIn($ledger)[6]['failure']);
        self::assertNull($this->remoteTip());
        self::assertSame(0, $this->changes->calls);
    }

    public function testAPushTheRemoteRefusesEndsAtThePushStepWithItsRecordedReasonAndOpensNoPullRequest(): void
    {
        $this->runner = $this->committingRunner();
        $this->remote->writeHook('pre-receive', "#!/bin/sh\nexit 1\n");
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Push, $outcome->last);
        self::assertSame('the remote refused the push of feat/task (git push exited with 1)', $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone, BuildStep::BuildJob, BuildStep::HandBackCheck, BuildStep::PrePushCheck], $outcome->passedSteps());
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($this->stepsIn($ledger), 'outcome'));
        self::assertSame(['push', 'the remote refused the push of feat/task (git push exited with 1)'], [$this->stepsIn($ledger)[6]['step'], $this->stepsIn($ledger)[6]['failure']]);
        self::assertContains(LedgerEventKind::PushRefused, $ledger->kinds());
        self::assertSame(LedgerEventKind::BuildStepEnded, array_last($ledger->kinds()));
        self::assertNull($this->remoteTip());
        self::assertSame(0, $this->changes->calls);
    }

    public function testThePrePushCheckRunsTheGatesStepsOnTheMirrorAndTheBundleInTheProjectsImage(): void
    {
        $this->runner = $this->committingRunner();

        (void) $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertInstanceOf(JobSpec::class, $this->checked);
        self::assertSame(self::IMAGE, $this->checked->image);
        self::assertSame([realpath(new Mirrors($this->mirrors)->pathOf('alpha')), RunCheckJob::MIRROR], [$this->checked->inputs[0]->hostPath, $this->checked->inputs[0]->containerPath]);
        self::assertSame(RunCheckJob::BUNDLE, $this->checked->inputs[1]->containerPath);
        self::assertSame(['feat/task', '--', ...new PhpGate()->checkSteps(null)], array_slice([...$this->checked->command], 6));
    }

    public function testAFailureAtThePrePushCheckEndsThereWithItsExitCodeRecordedNothingOfItsLogAndNoPush(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "failed 2\n", RunCheckJob::LOG => "PLANT-log tests failed\n"]);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the check command failed (exit code 2)', $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone, BuildStep::BuildJob, BuildStep::HandBackCheck], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone', 'build_job', 'hand_back_check', 'pre_push_check'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame('the check command failed (exit code 2)', $ledger->payloads()[5]['failure']);
        self::assertStringNotContainsString('PLANT', (string) json_encode($ledger->payloads()));
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
        self::assertSame(array_fill(0, 6, LedgerEventKind::BuildStepEnded), $ledger->kinds());
        self::assertNull($this->remoteTip());
        self::assertSame(0, $this->changes->calls);
    }

    public function testAPrePushCheckThatConflictsWithCurrentMainEndsThereOnTheConflictRouteAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "conflicts-with-main\n", RunCheckJob::LOG => "CONFLICT (content): PLANT-path.txt\n"]);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the branch conflicts with current main, so the check ran no step', $outcome->failure);
        self::assertSame($outcome->failure, $ledger->payloads()[5]['failure']);
        self::assertStringNotContainsString('PLANT', (string) json_encode($ledger->payloads()));
        self::assertNull($this->remoteTip());
        self::assertSame(0, $this->changes->calls);
    }

    public function testThePrePushCheckReadsTheMirrorUpdatedAfterTheBuildJobSoMainThatMovedDuringTheBuildIsInIt(): void
    {
        $this->runner = $this->runnerAfterWhich($this->committingRunner(), fn () => $this->remote->commitFile('moved.txt', 'main moved'));
        $mainInTheMirror = null;
        $this->checkRunner = self::createStub(JobRunner::class);
        $this->checkRunner->method('run')->willReturnCallback(function (JobSpec $spec) use (&$mainInTheMirror): JobResult {
            $mainInTheMirror = $this->git(['rev-parse', 'HEAD'], $spec->inputs[0]->hostPath);

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([RunCheckJob::RESULT => "passed\n", RunCheckJob::LOG => "ok\n"]));
        });

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertNull($outcome->failure);
        self::assertSame($this->git(['rev-parse', 'HEAD'], $this->remote->path), $mainInTheMirror);
    }

    public function testAMirrorThatCannotBeUpdatedBeforeThePrePushCheckEndsThereAndRunsNoCheckJob(): void
    {
        $moved = $this->remote->path.'-moved';
        $this->runner = $this->runnerAfterWhich($this->committingRunner(), fn () => rename($this->remote->path, $moved));

        try {
            $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($this->request(7, $this->remote->path));
        } finally {
            rename($moved, $this->remote->path);
        }

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertNotNull($outcome->failure);
        self::assertNull($this->checked);
        self::assertNull($this->remoteTip());
    }

    public function testACheckJobThatLeavesNoResultEndsAtThePrePushCheckWithFixedText(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::LOG => "PLANT-log\n"]);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame(CheckVerdict::NoResult->reason(), $outcome->failure);
        self::assertSame(CheckVerdict::NoResult->reason(), $ledger->payloads()[5]['failure']);
    }

    public function testACheckJobTheRunnerRefusesEndsAtThePrePushCheckWithItsMessage(): void
    {
        $this->runner = $this->committingRunner();
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobRefused::forLimits());
        $this->checkRunner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('A job needs a positive memory limit, CPU share and run time.', $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testACheckJobWhoseProcessTimesOutEndsAtThePrePushCheckWithFixedWords(): void
    {
        $this->runner = $this->committingRunner();
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(new ProcessTimedOutException(new Process(['docker', 'run', 'PLANT-command'], timeout: 60), ProcessTimedOutException::TYPE_GENERAL));
        $this->checkRunner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the pre-push check timed out after 60 seconds', $outcome->failure);
        self::assertSame('the pre-push check timed out after 60 seconds', $ledger->payloads()[5]['failure']);
    }

    public function testABundleFileThatCannotBeRemovedAfterThePrePushCheckFailsItWithTheRemovalFailure(): void
    {
        $this->runner = $this->committingRunner();
        $this->handBackFiles = $this->filesThatCannotRemoveAfter(2, 'Failed to remove file "handback.bundle": Permission denied');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('Failed to remove file "handback.bundle": Permission denied', $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testABundleTheHandBackCheckRefusesEndsThereWithItsRefusalRecordedAndNoLaterStepRuns(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame(HandBackRefusal::Unreadable->reason(), $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone, BuildStep::BuildJob], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone', 'build_job', 'hand_back_check'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame(HandBackRefusal::Unreadable->reason(), $ledger->payloads()[4]['failure']);
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testABundleOfAnotherBranchEndsAtTheHandBackCheckWithItsRefusal(): void
    {
        $this->runner = $this->committingRunner('feat/other');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame(HandBackRefusal::NamesAnotherRef->reason(), $outcome->failure);
        self::assertSame('failed', $ledger->payloads()[4]['outcome']);
        self::assertFileDoesNotExist($this->clone.'/b.txt');
    }

    public function testAGitCallKilledInTheHandBackCheckEndsThereWithFixedWordsAsItsRecordedFailure(): void
    {
        $this->runner = $this->committingRunner();
        $this->programs = new ProgramOnPath();
        $this->programs->killOn('git', 'unbundle');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame('the hand-back check was killed by signal 15', $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame('the hand-back check was killed by signal 15', $ledger->payloads()[4]['failure']);
        self::assertFileDoesNotExist($this->clone.'/b.txt');
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testAGitCallKilledWhileTheCloneTakesTheCheckedHeadEndsAtTheHandBackCheckWithFixedWords(): void
    {
        $this->runner = $this->committingRunner();
        $this->programs = new ProgramOnPath();
        $this->programs->killOn('git', '--hard');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame('git was killed by signal 15', $outcome->failure);
        self::assertSame('git was killed by signal 15', $ledger->payloads()[4]['failure']);
    }

    public function testAHandBackCheckWhoseScannerCannotRunEndsThereWithItsMessage(): void
    {
        $this->runner = $this->committingRunner();
        $this->scanner = $this->mirrors.'-no-scanner';
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertStringContainsString('secret scan', (string) $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testABundleFolderThatCannotBeCreatedEndsAtTheHandBackCheckWithItsMessage(): void
    {
        touch($this->mirrors.'-hand-backs');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertStringContainsString('-hand-backs', (string) $outcome->failure);
        self::assertSame('failed', $ledger->payloads()[4]['outcome']);
    }

    public function testARefusedBundleWhoseFileCannotBeRemovedEndsAtTheHandBackCheckWithTheRemovalFailureRecorded(): void
    {
        $this->handBackFiles = $this->filesThatCannotRemove('Failed to remove file "handback.bundle": Permission denied');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame('Failed to remove file "handback.bundle": Permission denied', $outcome->failure);
        self::assertSame(['task', 'mirror', 'clone', 'build_job', 'hand_back_check'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame('Failed to remove file "handback.bundle": Permission denied', $ledger->payloads()[4]['failure']);
    }

    public function testAnAcceptedBundleWhoseFileCannotBeRemovedFailsTheHandBackCheckWithTheRemovalFailureRecorded(): void
    {
        $this->runner = $this->committingRunner();
        $this->handBackFiles = $this->filesThatCannotRemove('Failed to remove file "handback.bundle": Permission denied');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame('Failed to remove file "handback.bundle": Permission denied', $outcome->failure);
        self::assertSame(['task', 'mirror', 'clone', 'build_job', 'hand_back_check'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame('Failed to remove file "handback.bundle": Permission denied', $ledger->payloads()[4]['failure']);
        self::assertStringEqualsFile($this->clone.'/b.txt', 'two');
    }

    public function testABundleThatCannotBeWrittenEndsAtTheHandBackCheckAndLeavesNoFileBehind(): void
    {
        $this->handBackFiles = $this->filesThatCannotWrite('disk full');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::HandBackCheck, $outcome->last);
        self::assertSame('disk full', $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testABuildJobThatHandsBackNoCommitEndsAtTheBuildJobStepWithItsVerdictRecorded(): void
    {
        new Filesystem()->remove($this->output.'/'.RunBuildJob::BUNDLE);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertSame(BuildVerdict::NoCommits->reason(), $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone', 'build_job'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame(BuildVerdict::NoCommits->reason(), $ledger->payloads()[3]['failure']);
    }

    public function testAQuestionThatBreaksTheQuestionRuleEndsAtTheBuildJobStepWithItsVerdictAndParksNothing(): void
    {
        new Filesystem()->dumpFile($this->output.'/'.RunBuildJob::QUESTION, '{"question":"PLANT-question","choices":[],"recommendation":"x"}');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertSame(BuildVerdict::QuestionRefused->reason(), $outcome->failure);
        self::assertFalse($outcome->parked());
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame(BuildVerdict::QuestionRefused->reason(), $ledger->payloads()[3]['failure']);
        self::assertStringNotContainsString('PLANT', (string) json_encode($ledger->payloads()));
    }

    public function testAQuestionTheDatabaseDoesNotStoreEndsAtTheBuildJobStepFailedWithFixedWordsAndRunsNoLaterStep(): void
    {
        new Filesystem()->dumpFile($this->output.'/'.RunBuildJob::QUESTION, '{"question":"PLANT-question?","choices":["a","b"],"recommendation":"a"}');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertSame('Issue 7 of project "alpha" was not parked: the database did not store its question', $outcome->failure);
        self::assertFalse($outcome->parked());
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone', 'build_job'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame($outcome->failure, $ledger->payloads()[3]['failure']);
        self::assertStringNotContainsString('PLANT', (string) json_encode($ledger->payloads()));
        self::assertSame([], glob($this->mirrors.'-hand-backs/*'));
    }

    public function testABuildJobWithoutPullRequestTextEndsAtTheBuildJobStep(): void
    {
        new Filesystem()->remove($this->output.'/'.RunBuildJob::PULL_REQUEST_TEXT);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertSame(BuildVerdict::NoPullRequestText->reason(), $outcome->failure);
        self::assertSame('failed', $ledger->payloads()[3]['outcome']);
    }

    public function testABareInvalidArgumentExceptionFromTheBuildJobPassesThrough(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(new InvalidArgumentException('a bug in the build job'));
        $this->runner = $runner;

        $this->expectExceptionObject(new InvalidArgumentException('a bug in the build job'));

        (void) $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($this->request(7, $this->remote->path));
    }

    public function testABuildJobTheRunnerRefusesEndsAtTheBuildJobStepWithItsMessage(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobRefused::forLimits());
        $this->runner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertSame('A job needs a positive memory limit, CPU share and run time.', $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testAProjectWithoutRoutingRulesEndsAtTheBuildJobStepAndNoJobRuns(): void
    {
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');
        $this->runner = $runner;
        $this->routing = new ConfiguredBuildRouting([], new ModelRouting());
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertStringContainsString('"alpha"', (string) $outcome->failure);
        self::assertSame(['task', 'mirror', 'clone', 'build_job'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[3]['outcome']);
    }

    public function testATierOnAProviderOtherThanClaudeCodeEndsAtTheBuildJobStepAndNoJobRuns(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'claude-sonnet-5-5'));
        $this->tiers = $tiers;
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');
        $this->runner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertStringContainsString('needs provider "claude_code"', (string) $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testACloneThatIsNoFolderAnymoreEndsAtTheBuildJobStepAndNoJobRuns(): void
    {
        $clone = $this->clone;
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(static function () use ($clone): ResolvedModel {
            new Filesystem()->remove($clone);

            return new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5');
        });
        $this->tiers = $tiers;
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');
        $this->runner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertStringContainsString('is no folder', (string) $outcome->failure);
        self::assertSame(['passed', 'passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
    }

    public function testTheBuildJobRunsInTheCloneOnTheBranchAndImageWithTheTaskAsItsFencedBrief(): void
    {
        $ran = null;
        $prompt = '';
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use (&$ran, &$prompt): JobResult {
            $ran = $spec;
            $prompt = (string) file_get_contents($spec->inputs[1]->hostPath);

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([]));
        });
        $this->runner = $runner;

        (void) $this->taskOf(new InMemoryLedger(), [Issues::task(7, with: ['body' => 'PLANT-body'])])->run($this->request(7, $this->remote->path));

        self::assertInstanceOf(JobSpec::class, $ran);
        self::assertSame([realpath($this->clone), RunBuildJob::CLONE], [$ran->inputs[0]->hostPath, $ran->inputs[0]->containerPath]);
        self::assertSame(self::IMAGE, $ran->image);
        self::assertSame('feat/task', [...$ran->command][6]);
        self::assertStringContainsString(new Fence()->wrap(UntrustedSource::Task, "Task 7\n\nPLANT-body")->rendered(), $prompt);
    }

    public function testABugTaskWithAllFourPartsReachesTheBuildJobWithTheTestFirstInstruction(): void
    {
        $prompt = '';
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use (&$prompt): JobResult {
            $prompt = (string) file_get_contents($spec->inputs[1]->hostPath);

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput());
        });
        $this->runner = $runner;
        $body = "**When:** a\n**Today:** b\n**Expected:** c\n**Must keep working:** d";

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7, ['intakt', 'bug'], ['body' => $body])])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        self::assertStringContainsString('write that test first', $prompt);
    }

    public function testABugHeadWhoseTestFailsOnTheBaseAndPassesOnTheHeadGoesOnToThePushAndReview(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "passed\n"]);
        $this->regressionTests = $this->regressionRuns(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed));
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7, ['intakt', 'bug'], ['body' => self::BUG_BODY])])->run($this->request(7, $this->remote->path));

        self::assertContains(BuildStep::PrePushCheck, $outcome->passedSteps());
        self::assertNotSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertNotNull($this->remoteTip());
        self::assertSame("## Evidence\n\n- Regression tests: `tests/FooTest.php`\n- On the base: failed\n- On the head: passed", $this->changes->text?->body);
    }

    public function testAFeatureHeadIsPushedWithItsBodyUnchanged(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "passed\n"]);
        $this->regressionTests = $this->regressionRuns(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed));

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7, ['intakt', 'feature'], ['body' => self::BUG_BODY])])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Push, $outcome->last);
        self::assertSame('', $this->changes->text?->body);
    }

    public function testABugHeadWhoseTestPassesOnTheBaseEndsAtThePrePushCheckWithTheReasonAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "passed\n"]);
        $this->regressionTests = $this->regressionRuns(new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Passed, RegressionRun::Passed));
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7, ['intakt', 'bug'], ['body' => self::BUG_BODY])])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the new test of a bug also passes on the base, so it proves nothing', $outcome->failure);
        self::assertSame('failed', $ledger->payloads()[5]['outcome']);
        self::assertSame($outcome->failure, $ledger->payloads()[5]['failure']);
        self::assertNull($this->remoteTip());
    }

    public function testABugHeadWithoutANewTestEndsAtThePrePushCheckWithTheReasonAndPushesNothing(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "passed\n"]);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7, ['intakt', 'bug'], ['body' => self::BUG_BODY])])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::PrePushCheck, $outcome->last);
        self::assertSame('the head of a bug adds no regression test', $outcome->failure);
        self::assertSame('failed', $ledger->payloads()[5]['outcome']);
        self::assertSame($outcome->failure, $ledger->payloads()[5]['failure']);
        self::assertNull($this->remoteTip());
    }

    public function testATierLabelOfTheIssueChoosesTheBuildsTier(): void
    {
        $tiers = $this->createMock(TierResolver::class);
        $tiers->expects(self::once())->method('resolve')->with('alpha', Tier::Strong)->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-opus-5-5'));
        $this->tiers = $tiers;

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7, ['intakt', 'model:strong'])])->run($this->request(7, $this->remote->path));

        self::assertContains(BuildStep::BuildJob, $outcome->passedSteps());
    }

    public function testAFailedCloneStepRunsNoBuildJob(): void
    {
        mkdir($this->clone);
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');
        $this->runner = $runner;
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
    }

    public function testAnIssueTheForgeDoesNotListEndsAtTheTaskStepAndNoMirrorIsMade(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(8)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Task, $outcome->last);
        self::assertSame('project "alpha" has no open issue 7', $outcome->failure);
        self::assertSame([], $outcome->passedSteps());
        self::assertSame(['task'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[0]['outcome']);
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testEveryStepRowOfARunCarriesOneRunIdAndAnotherRunOfTheTaskAnother(): void
    {
        $this->runner = $this->committingRunner();
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "failed 2\n", RunCheckJob::LOG => "tests failed\n"]);
        $ledger = new InMemoryLedger();
        (void) $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        (void) $this->taskOf($ledger, [Issues::task(8)])->run($this->request(7, $this->remote->path));

        $runs = array_values(array_filter(array_column($ledger->payloads(), 'run'), is_string(...)));
        self::assertCount(7, $runs);
        self::assertCount(1, array_unique(array_slice($runs, 0, 6)));
        self::assertNotSame($runs[0], $runs[6]);
    }

    public function testTheIssueReadAndTheTaskRowHappenWhileTheTasksStartLockIsHeld(): void
    {
        $held = [];
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturnCallback(function () use (&$held): null {
            $held['read'] = !$this->startLockOf(7)->acquire(false);

            return null;
        });
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(function () use (&$held): AppendResult {
            $held['row'] = !$this->startLockOf(7)->acquire(false);

            return AppendResult::appended(Uuid::v4());
        });

        (void) $this->buildTask($reads, $this->checkout(), $ledger)->run($this->request(7, $this->remote->path));

        self::assertSame(['read' => true, 'row' => true], $held);
        self::assertTrue($this->startLockOf(7)->acquire(false));
    }

    public function testTheStartLockOfAnotherTaskDoesNotHoldTheRunBack(): void
    {
        $other = $this->startLockOf(8);
        self::assertTrue($other->acquire(false));
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [])->run($this->request(7, $this->remote->path));

        self::assertSame('project "alpha" has no open issue 7', $outcome->failure);
        self::assertSame(['task'], array_column($ledger->payloads(), 'step'));
    }

    public function testAStartLockThatCannotBeTakenFailsTheTaskStepWithFixedWordsAndReadsNoIssue(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new RuntimeException('lock folder var/PLANT-locks is not writable'));
        $this->locks = new LockFactory($store);
        $reads = $this->createMock(IssueReads::class);
        $reads->expects(self::never())->method('readOpen');
        $ledger = new InMemoryLedger();

        $outcome = $this->buildTask($reads, $this->checkout(), $ledger)->run($this->request(7, $this->remote->path));

        self::assertSame(BuildTask::START_NOT_LOCKED, $outcome->failure);
        self::assertSame(BuildStep::Task, $outcome->last);
        self::assertSame([['task', 'failed', BuildTask::START_NOT_LOCKED]], array_map(static fn (array $row): array => [$row['step'], $row['outcome'], $row['failure']], $ledger->payloads()));
    }

    public function testAStartLockHeldByAnotherRunOfTheTaskFailsTheTaskStepAtOnceAndRunsNoOtherStep(): void
    {
        $other = $this->startLockOf(7);
        self::assertTrue($other->acquire(false));
        $reads = $this->createMock(IssueReads::class);
        $reads->expects(self::never())->method('readOpen');
        $ledger = new InMemoryLedger();

        $outcome = $this->buildTask($reads, $this->checkout(), $ledger)->run($this->request(7, $this->remote->path));

        self::assertTrue($other->isAcquired());
        self::assertSame(BuildTask::START_BUSY, $outcome->failure);
        self::assertSame(BuildStep::Task, $outcome->last);
        self::assertSame([['task', 'failed', BuildTask::START_BUSY]], array_map(static fn (array $row): array => [$row['step'], $row['outcome'], $row['failure']], $ledger->payloads()));
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testTheStartLockKeyOfOneProjectsTaskIsNotThatOfAnotherProjectWhoseNameRunsOn(): void
    {
        self::assertNotSame(new BuildRun('alpha:1', 2, 'a')->startLockKey(), new BuildRun('alpha', 12, 'a')->startLockKey());
        self::assertNotSame(new BuildRun('alpha', 7, 'a')->startLockKey(), new BuildRun('alpha', 8, 'a')->startLockKey());
    }

    public function testTheTaskIsReadThroughTheSingleIssueReadNeverTheListing(): void
    {
        $reads = $this->createMock(IssueReads::class);
        $reads->expects(self::once())->method('readOpen')->with('alpha', 7)->willReturn(null);
        $reads->expects(self::never())->method('listOpen');

        $outcome = $this->buildTask($reads, $this->checkout(), new InMemoryLedger())->run($this->request(7, $this->remote->path));

        self::assertSame('project "alpha" has no open issue 7', $outcome->failure);
    }

    public function testAForgeThatCannotBeReadEndsAtTheTaskStepWithItsMessage(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willThrowException(new ForgeReadFailed('GitHub answered 500'));
        $ledger = new InMemoryLedger();

        $outcome = $this->buildTask($reads, $this->checkout(), $ledger)->run($this->request(7, $this->remote->path));

        self::assertSame('GitHub answered 500', $outcome->failure);
        self::assertSame(['task'], array_column($ledger->payloads(), 'step'));
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testAMirrorThatCannotBeUpdatedEndsAtTheMirrorStepAndNoCloneIsMade(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->mirrors.'-missing-remote'));

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertSame([BuildStep::Task], $outcome->passedSteps());
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertNotEmpty($ledger->payloads()[1]['failure']);
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testAMirrorsFolderThatCannotBeCreatedEndsAtTheMirrorStepWithItsFailureRecorded(): void
    {
        touch($this->mirrors);
        $ledger = new InMemoryLedger();
        $task = $this->buildTask($this->listing([Issues::task(7)]), $this->checkoutIn($this->mirrors.'/mirrors'), $ledger);

        try {
            $outcome = $task->run($this->request(7, $this->remote->path));
        } finally {
            unlink($this->mirrors);
        }

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertTrue($outcome->failed());
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[1]['outcome']);
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testAMirrorWhoseAttributesFileIsRefusedEndsAtTheMirrorStepWithItsFailureRecorded(): void
    {
        $mirrors = new Mirrors($this->mirrors);
        $this->mirrorUpdate($mirrors, new InMemoryStore())->update('alpha', $this->remote->path);
        new Filesystem()->dumpFile($mirrors->pathOf('alpha').'/info/attributes', '*.txt text');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertStringContainsString('info/attributes', (string) $outcome->failure);
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testAMirrorLockThatCannotBeAcquiredEndsAtTheMirrorStepWithItsFailureRecorded(): void
    {
        $locks = self::createStub(PersistingStoreInterface::class);
        $locks->method('save')->willThrowException(new LockStorageException('the store is down'));
        $ledger = new InMemoryLedger();
        $task = $this->buildTask($this->listing([Issues::task(7)]), $this->checkoutWith(new Mirrors($this->mirrors), $locks), $ledger);

        $outcome = $task->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertSame('Failed to acquire the "git-mirror-alpha" lock.', $outcome->failure);
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testAGitCallKilledInTheMirrorStepEndsThereWithFixedWordsAsItsRecordedFailure(): void
    {
        $this->programs = new ProgramOnPath();
        $this->programs->killOn('git', '--remove-section');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertSame('git was killed by signal 15', $outcome->failure);
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame('git was killed by signal 15', $ledger->payloads()[1]['failure']);
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testAGitCallKilledInTheCloneStepEndsThereWithFixedWordsAndNoCloneIsLeft(): void
    {
        $this->programs = new ProgramOnPath();
        $this->programs->killOn('git', 'checkout');
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertSame('git was killed by signal 15', $outcome->failure);
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testACloneFolderWhoseRepositoryHasAnAttributesFileEndsAtTheCloneStep(): void
    {
        new Filesystem()->dumpFile($this->remote->path.'/.git/info/attributes', '*.txt text');
        $ledger = new InMemoryLedger();
        $request = new BuildTaskRequest('alpha', 7, $this->remote->path, 'feat/task', $this->remote->path.'/clone', self::IMAGE);

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($request);

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertStringContainsString('info/attributes', (string) $outcome->failure);
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[2]['outcome']);
        self::assertDirectoryDoesNotExist($this->remote->path.'/clone');
    }

    public function testABuildCloneThatCannotBeMadeEndsAtTheCloneStepAndLeavesTheFolderAlone(): void
    {
        mkdir($this->clone);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertStringContainsString('the folder already exists', (string) $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[2]['outcome']);
    }

    public function testABranchGitDoesNotAcceptEndsAtTheCloneStep(): void
    {
        $request = new BuildTaskRequest('alpha', 7, $this->remote->path, 'bad..name', $this->clone, self::IMAGE);

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($request);

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertStringContainsString('is not a branch name git accepts', (string) $outcome->failure);
    }

    public function testALedgerThatDoesNotRecordAStepStopsTheRunBeforeTheNextStep(): void
    {
        $ledger = new InMemoryLedger(1);

        try {
            (void) $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));
            self::fail('the run must stop');
        } catch (BuildStepNotRecorded $failure) {
            self::assertStringContainsString('did not record the mirror step of issue 7', $failure->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->clone);
    }

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->stops = new RecordedBuildStops();
        $this->handBackFiles = new Filesystem();
        $this->changes = new RecordingChangeOpening();
        $this->mirrors = sys_get_temp_dir().'/intakt-build-task-'.bin2hex(random_bytes(6));
        $this->clone = $this->mirrors.'-clone';
        $this->output = $this->mirrors.'-output';
        $this->routing = new ConfiguredBuildRouting(['alpha' => ['tier' => 'standard', 'sensitive_paths' => [], 'escalate_on' => []]], new ModelRouting());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
        $this->tiers = $tiers;
        $this->runner = new ScriptedJobRunner($this->output);
        $this->checkRunner = $this->checkLeaving([RunCheckJob::RESULT => "passed\n", RunCheckJob::LOG => "ok\n"]);
        new Filesystem()->dumpFile($this->output.'/'.RunBuildJob::BUNDLE, 'BUNDLE');
        new Filesystem()->dumpFile($this->output.'/'.RunBuildJob::PULL_REQUEST_TEXT, "feat: task 7\n");
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        $this->programs?->remove();
        new Filesystem()->remove([$this->mirrors, $this->clone, $this->clone.'-job', $this->output, $this->mirrors.'-prompts', $this->mirrors.'-hand-backs', $this->mirrors.'-key', $this->mirrors.'-key.pub', $this->mirrors.'-checks']);
        $this->remote->remove();
    }

    /**
     * @param list<ListedIssue> $issues
     */
    private function taskOf(InMemoryLedger $ledger, array $issues): BuildTask
    {
        return $this->buildTask($this->listing($issues), $this->checkout(), $ledger);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stepsIn(InMemoryLedger $ledger): array
    {
        return array_values(array_filter($ledger->payloads(), static fn (array $payload): bool => isset($payload['step'])));
    }

    /**
     * @param array<string, string> $files
     */
    private function checkLeaving(array $files): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($files): JobResult {
            $this->checked = $spec;

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of($files));
        });

        return $runner;
    }

    // Removes the earlier bundle files as the real filesystem does, because the run reaches the pre-push check only when the hand-back check passed.
    private function filesThatCannotRemoveAfter(int $checks, string $message): Filesystem
    {
        $real = new Filesystem();
        $removed = 0;
        $files = self::createStub(Filesystem::class);
        $files->method('mkdir')->willReturnCallback($real->mkdir(...));
        $files->method('tempnam')->willReturnCallback($real->tempnam(...));
        $files->method('dumpFile')->willReturnCallback($real->dumpFile(...));
        $files->method('remove')->willReturnCallback(static function (string $file) use ($real, &$removed, $checks, $message): void {
            if (++$removed >= $checks) {
                throw new IOException($message);
            }

            $real->remove($file);
        });

        return $files;
    }

    // Writes the bundle file as the real filesystem does, so the check runs on it, and fails only its removal.
    private function filesThatCannotRemove(string $message): Filesystem
    {
        $real = new Filesystem();
        $files = self::createStub(Filesystem::class);
        $files->method('mkdir')->willReturnCallback($real->mkdir(...));
        $files->method('tempnam')->willReturnCallback($real->tempnam(...));
        $files->method('dumpFile')->willReturnCallback($real->dumpFile(...));
        $files->method('remove')->willThrowException(new IOException($message));

        return $files;
    }

    private function filesThatCannotWrite(string $message): Filesystem
    {
        $real = new Filesystem();
        $files = self::createStub(Filesystem::class);
        $files->method('mkdir')->willReturnCallback($real->mkdir(...));
        $files->method('tempnam')->willReturnCallback($real->tempnam(...));
        $files->method('dumpFile')->willThrowException(new IOException($message));
        $files->method('remove')->willReturnCallback($real->remove(...));

        return $files;
    }

    // In a clone of the build clone, because the job commits in a clone of its own in the container, and bundled against main, because docker/job/build.sh bundles it so.
    private function committingRunner(string $ref = 'feat/task', string $text = "feat: task 7\n"): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($ref, $text): JobResult {
            $job = $this->clone.'-job';
            $this->git(['clone', '--quiet', $spec->inputs[0]->hostPath, $job], dirname($job));
            new Filesystem()->dumpFile($job.'/b.txt', 'two');
            $this->git(['add', 'b.txt'], $job);
            $this->git([...self::IDENTITY, 'commit', '--quiet', '-m', 'Build task 7'], $job);
            $this->git(['update-ref', 'refs/heads/'.$ref, 'HEAD'], $job);
            $this->git(['bundle', 'create', '--quiet', $job.'.bundle', $ref, '^origin/main'], $job);
            $bundle = (string) file_get_contents($job.'.bundle');
            new Filesystem()->remove([$job, $job.'.bundle']);

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([RunBuildJob::BUNDLE => $bundle, RunBuildJob::PULL_REQUEST_TEXT => $text]));
        });

        return $runner;
    }

    // Because the remote can move while the build job runs, and only a change after the job shows which mirror the pre-push check reads.
    private function runnerAfterWhich(JobRunner $built, callable $change): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use ($built, $change): JobResult {
            $result = $built->run($spec);
            $change();

            return $result;
        });

        return $runner;
    }

    private function taskWithEvidence(): ListedIssue
    {
        return Issues::task(7, with: ['body' => "Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n"]);
    }

    private function inClone(string ...$arguments): string
    {
        return $this->git(array_values($arguments), $this->clone);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * @param list<ListedIssue> $issues
     */
    private function listing(array $issues): IssueReads
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturnCallback(static fn (string $project, int $number): ?ListedIssue => array_find($issues, static fn (ListedIssue $issue): bool => $issue->number === $number));

        return $reads;
    }

    private function checkout(): GitBuildCheckout
    {
        return $this->checkoutIn($this->mirrors);
    }

    private function startLockOf(int $task): SharedLockInterface
    {
        return $this->locks->createLock(new BuildRun('alpha', $task, 'any run')->startLockKey());
    }

    private function regressionRuns(RegressionRuns $runs): RegressionTests
    {
        $regressionTests = self::createStub(RegressionTests::class);
        $regressionTests->method('run')->willReturn($runs);

        return $regressionTests;
    }

    private function buildTask(IssueReads $issues, GitBuildCheckout $checkout, Ledger $ledger): BuildTask
    {
        $configured = self::createStub(ConfiguredRepositories::class);
        $configured->method('nameOf')->willReturn($this->configuredRepository);
        $builds = new RunBuildJob($this->tiers, new BuilderPrompt(new Fence(), new PromptCut(new Fence())), $this->runner, new PhpGate(), new GatesAtOnce(12, 1), 1500, new Filesystem(), self::SCRIPT, $this->mirrors.'-prompts', $this->stops);
        $git = new RepositoryGit(new HardenedGit());
        $checks = new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan($this->scanner));
        $files = new HandBackFile($checkout, $this->handBackFiles, $this->mirrors.'-hand-backs');
        $dependencies = new CheckJobsFor($this->mirrors.'-checks')->dependencies($this->checkRunner, new PhpGate());
        $checkJobs = new RunCheckJob($checks, new LostContentCheck($git), $dependencies, $this->checkRunner, new PhpGate(), new Filesystem(), self::CHECK_SCRIPT);

        // On a database that cannot be opened, because a parking that works is proven on the install's own database in BuildTaskParksOnSqliteTest.
        $parked = new ParkedTasks(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->mirrors.'-missing/data.db']), $ledger, self::createStub(ManagerRegistry::class), new MockClock());

        $texts = new PullRequestTextCheck($checkout, new RoutePullRequestText(new PullRequestTextRoutes($this->textLedger ?? $ledger, $this->textLocks ?? $this->locks)));

        return new BuildTask($issues, $checkout, $this->routing, $builds, new HandBackCheck($checkout, $checks, $files), new PrePushCheck($checkJobs, $files, $this->regressionTests ?? new NoRegressionTests(), new NullLogger()), $texts, new PushStep($this->branchPush($ledger), $checkout), $parked, $ledger, new PushRemoteCheck($configured), $this->locks);
    }

    // Records in the run's own ledger, as the container wires it, so the push's events sit between the steps' events.
    private function branchPush(Ledger $ledger): PushBranch
    {
        $branches = new BranchGit(new HardenedGit(), new GuardedRun());
        $file = $this->mirrors.'-key';
        $key = new SigningKey(is_file($file) ? $file : PushScenario::generateKey($file), new Filesystem(), new GuardedRun());

        return new PushBranch($branches, new SignedCommits($branches, $key), new PushRecords($ledger), $this->changes, new LockFactory(new InMemoryStore()));
    }

    private function checkoutIn(string $directory): GitBuildCheckout
    {
        return $this->checkoutWith(new Mirrors($directory), new InMemoryStore());
    }

    private function checkoutWith(Mirrors $mirrors, PersistingStoreInterface $locks): GitBuildCheckout
    {
        return new GitBuildCheckout($this->mirrorUpdate($mirrors, $locks), new MakeBuildClone(new HardenedGit(), new Filesystem(), $mirrors), $mirrors, new TakeCheckedHead(new HardenedGit(), $mirrors));
    }

    private function mirrorUpdate(Mirrors $mirrors, PersistingStoreInterface $locks): UpdateMirror
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory($locks), $mirrors);
    }

    private function request(int $issue, string $remote): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', $issue, $remote, 'feat/task', $this->clone, self::IMAGE);
    }

    private function remoteTip(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feat/task'], $this->remote->path);

        return 0 === $process->run() ? trim($process->getOutput()) : null;
    }
}
