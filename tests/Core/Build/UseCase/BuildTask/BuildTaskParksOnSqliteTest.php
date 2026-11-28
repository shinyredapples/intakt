<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\BugPart;
use Intakt\Core\Build\Domain\BugReport;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\AskedQuestion;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskCommand;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskInput;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\EndedStep;
use Intakt\Core\Build\UseCase\BuildTask\HandBackCheck;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Build\UseCase\BuildTask\PullRequestTextCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushRemoteCheck;
use Intakt\Core\Build\UseCase\BuildTask\PushStep;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobEndFailed;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogReading;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildSessionWatch;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\HandBackChecks;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Contract\JobWatch;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Build\ScriptedJobRunner;
use Intakt\Tests\Core\Build\UseCase\RunBuildJob\BuildLogLines;
use Intakt\Tests\Core\Job\OutputFiles;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// On the install's own database, because the parked row and the asked step share its transaction while the earlier steps go through the same ledger; a stopped job's row is read back from the same table.
#[Group('persistence')]
#[CoversClass(BuildTask::class)]
#[CoversClass(BuildTaskOutcome::class)]
#[CoversClass(BuildTaskCommand::class)]
#[UsesClass(BuildTaskInput::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(AskedQuestion::class)]
#[UsesClass(EndedStep::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(PushRemoteCheck::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BugReport::class)]
#[UsesClass(BugPart::class)]
#[UsesClass(TaskKind::class)]
#[UsesClass(HandBackCheck::class)]
#[UsesClass(HandBackFile::class)]
#[UsesClass(PrePushCheck::class)]
#[UsesClass(PullRequestTextCheck::class)]
#[UsesClass(PushStep::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(Issues::class)]
#[UsesClass(ScriptedJobRunner::class)]
#[UsesClass(RunBuildJob::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildSessionWatch::class)]
#[UsesClass(BuildLogReading::class)]
#[UsesClass(BuildStopRule::class)]
#[UsesClass(BuildWatch::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(BuildJobEndFailed::class)]
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
final class BuildTaskParksOnSqliteTest extends KernelTestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private const string QUESTION = '{"question":"PLANT-question: keep the old flag?","choices":["keep it","drop it"],"recommendation":"drop it"}';

    private string $folder = '';

    private RecordedBuildStops $stops;

    private MockClock $clock;

    private ?BuildLimits $limits = null;

    public function testABuildJobThatEndsWithAQuestionParksTheTaskWithItsAskedStepAndRunsNoLaterStep(): void
    {
        $outcome = $this->buildTask()->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertSame([BuildStep::BuildJob, null, true], [$outcome->last, $outcome->failure, $outcome->parked()]);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone], $outcome->passedSteps());
        self::assertSame(
            [['project' => 'alpha', 'task' => 7, 'question' => '"PLANT-question: keep the old flag?"', 'recommendation' => '"drop it"']],
            array_map(static fn (array $row): array => [...$row, 'task' => is_numeric($row['task']) ? (int) $row['task'] : $row['task']], $this->connection()->fetchAllAssociative('SELECT project, task, question, recommendation FROM build_parked_task')),
        );
        $payloads = array_map(static fn (mixed $payload): string => is_string($payload) ? $payload : '', $this->connection()->fetchFirstColumn('SELECT payload FROM ledger_event ORDER BY position'));
        $run = 1 === preg_match('/"run":"([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})"/', $payloads[0] ?? '', $match) ? $match[1] : 'no run';
        self::assertSame(
            [
                ['build_step_ended', '{"project":"alpha","task":"7","run":"'.$run.'","step":"task","outcome":"passed","failure":null,"stop_cause":null}'],
                ['build_step_ended', '{"project":"alpha","task":"7","run":"'.$run.'","step":"mirror","outcome":"passed","failure":null,"stop_cause":null}'],
                ['build_step_ended', '{"project":"alpha","task":"7","run":"'.$run.'","step":"clone","outcome":"passed","failure":null,"stop_cause":null}'],
                ['build_step_ended', '{"project":"alpha","task":"7","run":"'.$run.'","step":"build_job","outcome":"asked","failure":null,"stop_cause":null}'],
                ['task_parked', '{"project":"alpha","task":"7","run":"'.$run.'"}'],
            ],
            array_map(array_values(...), $this->connection()->fetchAllAssociative('SELECT kind, payload FROM ledger_event ORDER BY position')),
        );
        self::assertSame([], glob($this->folder.'/hand-backs/*'));
    }

    public function testTheCommandPrintsThePassedStepsAndTheParkedLineButNeverTheQuestion(): void
    {
        $application = new Application();
        $application->addCommand(new BuildTaskCommand($this->buildTask()));
        $tester = new CommandTester($application->find('intakt:build:task'));

        $tester->execute(['project' => 'alpha', 'issue' => '7', '--remote' => 'remote', '--branch' => 'feat/task', '--directory' => $this->folder.'/clone', '--image' => 'intakt-job-alpha:1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("task passed\nmirror passed\nclone passed\ntitle Task 7", $tester->getDisplay());
        self::assertStringContainsString('build_job asked: the task is parked until its question is answered', $tester->getDisplay());
        self::assertStringNotContainsString('build_job passed', $tester->getDisplay());
        self::assertStringNotContainsString('PLANT', $tester->getDisplay());
    }

    public function testABugTaskWithAMissingPartIsParkedWithAQuestionNamingItAndRunsNoBuildJob(): void
    {
        $task = Issues::task(7, ['intakt', 'bug'], ['body' => "**When:** open the page\n**Today:** it fails\n**Expected:** it loads"]);

        $outcome = $this->buildTask($task)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertSame([BuildStep::BuildJob, null, true], [$outcome->last, $outcome->failure, $outcome->parked()]);
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone], $outcome->passedSteps());
        // The scripted job would have asked another question, so this one proves the job never ran.
        self::assertStringContainsString('missing: Must keep working.', $this->question());
        $payload = $this->connection()->fetchOne("SELECT payload FROM ledger_event WHERE kind = 'build_step_ended' ORDER BY position DESC LIMIT 1");
        self::assertIsString($payload);
        self::assertStringContainsString('"step":"build_job","outcome":"asked","failure":null,"stop_cause":null}', $payload);
    }

    public function testABugTaskWithAnEmptyPartIsParkedWithAQuestionNamingIt(): void
    {
        $task = Issues::task(7, ['intakt', 'bug'], ['body' => "## When\nopen the page\n## Today\n\n## Expected\nit loads\n## Must keep working\nthe list"]);

        $outcome = $this->buildTask($task)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertTrue($outcome->parked());
        self::assertStringContainsString('missing: Today.', $this->question());
    }

    public function testABugTaskWithAllFourPartsGoesOnToTheBuildJob(): void
    {
        $task = Issues::task(7, ['intakt', 'bug'], ['body' => "**When:** a\n**Today:** b\n**Expected:** c\n**Must keep working:** d"]);

        $outcome = $this->buildTask($task)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertTrue($outcome->parked());
        self::assertStringContainsString('PLANT-question', $this->question());
    }

    public function testATaskWithoutTheBugLabelIsNotCheckedForTheFourParts(): void
    {
        $outcome = $this->buildTask(Issues::task(7, ['intakt'], ['body' => 'no parts at all']))->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertTrue($outcome->parked());
        self::assertStringContainsString('PLANT-question', $this->question());
    }

    #[TestWith([BuildStopCause::NoProgress])]
    #[TestWith([BuildStopCause::RunawayCeiling])]
    #[TestWith([BuildStopCause::PerTaskTotal])]
    public function testAStoppedBuildJobIsStoredWithItsStopCauseInTheBuildJobLedgerRow(BuildStopCause $cause): void
    {
        new Filesystem()->remove([$this->folder.'/output/'.RunBuildJob::QUESTION, $this->folder.'/output/'.RunBuildJob::BUNDLE]);
        $stopped = new BuildJob('alpha', $this->folder.'/clone', 'feat/task', new BuildBrief('x', new Decisions(), null), Tier::Standard, 'intakt-job-alpha:1');
        $scripted = new ScriptedJobRunner($this->folder.'/output');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($scripted, $stopped, $cause): JobResult {
            $this->stops->record($stopped, $cause);

            return $scripted->run($spec);
        });

        $outcome = $this->buildTask(runner: $runner)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertSame([BuildStep::BuildJob, BuildVerdict::NoCommits->reason()], [$outcome->last, $outcome->failure]);
        $payload = $this->connection()->fetchOne("SELECT payload FROM ledger_event WHERE kind = 'build_step_ended' ORDER BY position DESC LIMIT 1");
        self::assertIsString($payload);
        self::assertStringEndsWith('"step":"build_job","outcome":"failed","failure":"'.BuildVerdict::NoCommits->reason().'","stop_cause":"'.$cause->value.'"}', $payload);
    }

    /**
     * @param list<string> $logs
     */
    #[DataProvider('stopsOfTheWatch')]
    public function testARunningBuildJobStoppedByTheWatchIsStoredWithItsStopCauseInTheBuildJobLedgerRow(BuildStopCause $cause, BuildLimits $limits, array $logs): void
    {
        new Filesystem()->remove([$this->folder.'/output/'.RunBuildJob::QUESTION, $this->folder.'/output/'.RunBuildJob::BUNDLE]);
        $this->limits = $limits;
        $scripted = new ScriptedJobRunner($this->folder.'/output');
        $runner = self::createStub(JobRunner::class);
        // The mock clock moves one interval before each look, so the order of the looks is the test's and nothing waits.
        $runner->method('run')->willReturnCallback(function (JobSpec $spec, ?JobWatch $watch) use ($scripted, $logs): JobResult {
            self::assertInstanceOf(JobWatch::class, $watch);
            foreach ($logs as $log) {
                $this->clock->sleep($watch->intervalSeconds());
                if ($watch->endsSession(OutputFiles::of([RunBuildJob::LOG => $log]))) {
                    break;
                }
            }

            return $scripted->run($spec);
        });

        $outcome = $this->buildTask(runner: $runner)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        $payload = $this->connection()->fetchOne("SELECT payload FROM ledger_event WHERE kind = 'build_step_ended' ORDER BY position DESC LIMIT 1");
        self::assertIsString($payload);
        self::assertStringEndsWith('"stop_cause":"'.$cause->value.'"}', $payload);
    }

    /**
     * @return iterable<string, array{BuildStopCause, BuildLimits, list<string>}>
     */
    public static function stopsOfTheWatch(): iterable
    {
        yield 'no progress' => [BuildStopCause::NoProgress, new BuildLimits(100, 60, 1000, 1000), [BuildLogLines::usage('m1', 150), BuildLogLines::usage('m1', 150)]];
        yield 'runaway ceiling' => [BuildStopCause::RunawayCeiling, new BuildLimits(100, 60, 500, 10000), [BuildLogLines::usage('m1', 150).BuildLogLines::edit('c1', 'a', 'b'), BuildLogLines::usage('m1', 150).BuildLogLines::usage('m2', 450).BuildLogLines::edit('c1', 'a', 'b')]];
        yield 'per-task total' => [BuildStopCause::PerTaskTotal, new BuildLimits(100, 60, 500, 300), [BuildLogLines::usage('m1', 350).BuildLogLines::edit('c1', 'a', 'b')]];
    }

    public function testAStoppedBuildJobWhoseEndEventIsNotRecordedIsStoredWithItsStopCauseInTheFailedRow(): void
    {
        $stopped = new BuildJob('alpha', $this->folder.'/clone', 'feat/task', new BuildBrief('x', new Decisions(), null), Tier::Standard, 'intakt-job-alpha:1');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($stopped): never {
            $this->stops->record($stopped, BuildStopCause::RunawayCeiling);

            throw JobEventNotRecorded::for('job_ended', 'the database is locked');
        });

        $outcome = $this->buildTask(runner: $runner)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        self::assertSame(BuildStep::BuildJob, $outcome->last);
        $payload = $this->connection()->fetchOne("SELECT payload FROM ledger_event WHERE kind = 'build_step_ended' ORDER BY position DESC LIMIT 1");
        self::assertIsString($payload);
        self::assertStringEndsWith('"step":"build_job","outcome":"failed","failure":"The ledger did not record the job event \"job_ended\": the database is locked","stop_cause":"runaway_ceiling"}', $payload);
    }

    public function testAStoppedBuildJobThatAsksAQuestionKeepsItsStopCauseInTheBuildJobLedgerRow(): void
    {
        self::assertSame(['build_job', 'asked', 'no_progress'], $this->lastBuildJobRowOfAStoppedJobThatAsks());
    }

    public function testAStoppedBuildJobWhoseParkingIsRefusedKeepsItsStopCauseInTheFailedBuildJobRow(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The trigger that refuses the parked row is written in SQLite.');
        }

        $this->connection()->executeStatement("CREATE TRIGGER refuse_park BEFORE INSERT ON build_parked_task BEGIN SELECT RAISE(ABORT, 'refused'); END");

        self::assertSame(['build_job', 'failed', 'no_progress'], $this->lastBuildJobRowOfAStoppedJobThatAsks());
    }

    protected function setUp(): void
    {
        $this->stops = new RecordedBuildStops();
        $this->clock = new MockClock('2026-01-01 00:00:00');
        $this->folder = sys_get_temp_dir().'/intakt-build-task-parks-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/clone');
        // A bundle and a text beside the question, because a question ends the build whatever else the job left.
        new Filesystem()->dumpFile($this->folder.'/output/'.RunBuildJob::BUNDLE, 'BUNDLE');
        new Filesystem()->dumpFile($this->folder.'/output/'.RunBuildJob::PULL_REQUEST_TEXT, "feat: task 7\n");
        new Filesystem()->dumpFile($this->folder.'/output/'.RunBuildJob::QUESTION, self::QUESTION);
        $tool = new SchemaTool($this->manager());
        $tool->dropDatabase();
        $tool->createSchema($this->manager()->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
        parent::tearDown();
    }

    /**
     * @return list<mixed>
     */
    private function lastBuildJobRowOfAStoppedJobThatAsks(): array
    {
        $stopped = new BuildJob('alpha', $this->folder.'/clone', 'feat/task', new BuildBrief('x', new Decisions(), null), Tier::Standard, 'intakt-job-alpha:1');
        $scripted = new ScriptedJobRunner($this->folder.'/output');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($scripted, $stopped): JobResult {
            $this->stops->record($stopped, BuildStopCause::NoProgress);

            return $scripted->run($spec);
        });

        (void) $this->buildTask(runner: $runner)->run(new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', $this->folder.'/clone', 'intakt-job-alpha:1'));

        $payload = $this->connection()->fetchOne("SELECT payload FROM ledger_event WHERE kind = 'build_step_ended' ORDER BY position DESC LIMIT 1");
        self::assertIsString($payload);
        $row = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($row);

        return [$row['step'], $row['outcome'], $row['stop_cause']];
    }

    private function buildTask(?ListedIssue $task = null, ?JobRunner $runner = null): BuildTask
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn($task ?? Issues::task(7));
        $checkout = self::createStub(BuildCheckout::class);
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturn(new TierChoice(Tier::Standard, TierReason::Default));
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
        $builds = new RunBuildJob($tiers, new BuilderPrompt(new Fence(), new PromptCut(new Fence())), $runner ?? new ScriptedJobRunner($this->folder.'/output'), new PhpGate(), new GatesAtOnce(12, 1), 1500, new Filesystem(), self::SCRIPT, $this->folder.'/prompts', $this->stops, new BuildStopRule($this->clock), $this->limits ?? new BuildLimits(1, 1, 1, 1));
        $files = new HandBackFile($checkout, new Filesystem(), $this->folder.'/hand-backs');
        $parked = self::getContainer()->get(ParkedTasks::class);
        self::assertInstanceOf(ParkedTasks::class, $parked);

        return new BuildTask($reads, $checkout, $routing, $builds, new HandBackCheck($checkout, self::createStub(HandBackChecks::class), $files), new PrePushCheck(self::createStub(CheckJobs::class), $files, new NoRegressionTests(), new NullLogger()), new PullRequestTextCheck($checkout, self::createStub(PullRequestTextRound::class)), new PushStep(self::createStub(BranchPush::class), $checkout), $parked, $this->ledger(), new PushRemoteCheck(self::createStub(ConfiguredRepositories::class)), new LockFactory(new InMemoryStore()));
    }

    private function question(): string
    {
        $question = $this->connection()->fetchOne('SELECT question FROM build_parked_task');
        self::assertIsString($question);

        return $question;
    }

    private function ledger(): Ledger
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);

        return $ledger;
    }

    private function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    private function manager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
