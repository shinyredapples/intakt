<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\ContainerJobRunner;
use Intakt\Core\Job\Infrastructure\ContainerState;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\JobContainer;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
use Intakt\Core\Job\Infrastructure\JobContainers;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ContainerJobRunner::class)]
#[CoversClass(JobContainers::class)]
#[CoversClass(JobContainerDefinition::class)]
#[CoversClass(JobOutputDirectories::class)]
#[CoversClass(JobLedger::class)]
#[CoversClass(JobLocks::class)]
#[CoversClass(OrphanedJobs::class)]
#[CoversClass(DockerEngine::class)]
#[UsesClass(ContainerState::class)]
#[UsesClass(JobContainer::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobRefused::class)]
#[UsesClass(JobOutputRefused::class)]
#[UsesClass(DockerEngineFailed::class)]
#[UsesClass(JobEventNotRecorded::class)]
final class ContainerJobRunnerTest extends TestCase
{
    private const int MEMORY = 512 * 1024 * 1024;

    private string $root = '';
    private FakeDockerEngine $engine;
    private InMemoryLedger $ledger;
    private InMemoryStore $locks;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-jobs-'.bin2hex(random_bytes(4));
        $this->engine = new FakeDockerEngine();
        $this->ledger = new InMemoryLedger();
        $this->locks = new InMemoryStore();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testAJobRunsWithAReadOnlyRootAndAWorkAreaOnTmpfs(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        $host = $this->engine->definitionOf('build-1')['HostConfig'];
        self::assertIsArray($host);
        self::assertTrue($host['ReadonlyRootfs']);
        self::assertSame(['/work' => 'rw,nosuid,nodev'], $host['Tmpfs']);
        self::assertSame(['ALL'], $host['CapDrop']);
        self::assertSame(['no-new-privileges'], $host['SecurityOpt']);
        self::assertSame('none', $host['NetworkMode']);
    }

    public function testInputsAreMountedReadOnlyAndTheOutputDirectoryIsTheOnlyWritableMount(): void
    {
        $this->whenJobRuns($this->spec('build-1', [new JobInput('/srv/mirror/sample.git', '/input/repository')]));

        $mounts = $this->mountsOf('build-1');
        self::assertSame([
            ['Type' => 'bind', 'Source' => '/srv/mirror/sample.git', 'Target' => '/input/repository', 'ReadOnly' => true],
            ['Type' => 'bind', 'Source' => $this->root.'/build-1', 'Target' => '/output', 'ReadOnly' => false],
        ], $mounts);
    }

    public function testAJobGetsAWipedHomeAndNoEnvironmentFromTheOrchestrator(): void
    {
        putenv('GITHUB_APP_PRIVATE_KEY=held-by-the-orchestrator');

        try {
            $this->whenJobRuns($this->spec('build-1'));
        } finally {
            putenv('GITHUB_APP_PRIVATE_KEY');
        }

        $definition = $this->engine->definitionOf('build-1');
        self::assertSame(['HOME=/work', 'TMPDIR=/work', 'INTAKT_JOB_ID=build-1'], $definition['Env']);
        self::assertSame(sprintf('%d:%d', posix_getuid(), posix_getgid()), $definition['User']);
    }

    public function testMemoryAndCpuLimitsAreSetOnTheJobsOwnContainer(): void
    {
        $this->whenJobRuns(new JobSpec(new JobId('build-1'), 'sample:latest', ['true'], [], new JobLimits(self::MEMORY, 1.5, 60)));

        $host = $this->engine->definitionOf('build-1')['HostConfig'];
        self::assertIsArray($host);
        self::assertSame(self::MEMORY, $host['Memory']);
        self::assertSame(self::MEMORY, $host['MemorySwap']);
        self::assertSame(1_500_000_000, $host['NanoCpus']);
    }

    public function testTheOutputTheJobWroteIsReadAfterExit(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            file_put_contents($output.'/result.json', '{"done":true}');

            return [0, false];
        });

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['result.json'], $result->output->names());
        self::assertSame('{"done":true}', $result->output->contents('result.json'));
        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
    }

    public function testAJobCannotReachAnotherJobsOutputDirectory(): void
    {
        $seen = [];
        $this->engine->behaveAs('build-1', function (string $output) use (&$seen): array {
            file_put_contents($output.'/bundle', 'first');
            $seen['mode'] = fileperms($output) & 0o777;

            return [0, false];
        });

        $this->whenJobRuns($this->spec('build-1'));
        $this->whenJobRuns($this->spec('build-2'));

        $sources = array_column($this->mountsOf('build-2'), 'Source');
        self::assertNotContains($this->root.'/build-1', $sources);
        self::assertSame([$this->root.'/build-2'], $sources);
        self::assertSame(0o700, $seen['mode']);
        self::assertDirectoryDoesNotExist($this->root.'/build-1');
    }

    public function testAMemoryKillIsRecordedAsTheJobsCause(): void
    {
        $this->engine->behaveAs('build-1', static fn (string $output): array => [137, true]);

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(JobCause::MemoryKilled, $result->end->cause);
        self::assertSame([LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame([['job' => 'build-1', 'cause' => 'oom', 'exit_code' => 137]], $this->ledger->payloads());
    }

    public function testARunawayJobIsKilledAloneAndTheOtherJobKeepsItsOwnCause(): void
    {
        $this->engine->behaveAs('runaway', static fn (string $output): array => [137, true]);

        $this->whenJobRuns($this->spec('runaway'));
        $this->whenJobRuns($this->spec('neighbor'));

        self::assertSame([
            ['job' => 'runaway', 'cause' => 'oom', 'exit_code' => 137],
            ['job' => 'neighbor', 'cause' => 'exited', 'exit_code' => 0],
        ], $this->ledger->payloads());
    }

    public function testAJobPastItsRunTimeIsKilledAndRecordedAsTimedOut(): void
    {
        $this->engine->hang('build-1');

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::TimedOut, 137), $result->end);
        self::assertSame([['job' => 'build-1', 'cause' => 'timed_out', 'exit_code' => 137]], $this->ledger->payloads());
        self::assertSame([], $this->engine->containerIds());
    }

    public function testTheContainerAndItsOutputDirectoryAreRemovedAfterExit(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([], $this->engine->containerIds());
        self::assertDirectoryDoesNotExist($this->root.'/build-1');
    }

    public function testTheContainerIsRemovedWhenTheEngineAnswersGarbled(): void
    {
        $this->engine->garbleState();

        try {
            $this->whenJobRuns($this->spec('build-1'));
            self::fail('A garbled state must fail the job.');
        } catch (DockerEngineFailed $failure) {
            self::assertStringContainsString('unexpected body', $failure->getMessage());
        }

        self::assertSame([], $this->engine->containerIds());
        self::assertDirectoryDoesNotExist($this->root.'/build-1');
    }

    public function testALinkInTheOutputIsRefusedAndNothingIsLeftBehind(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            symlink('/etc/hostname', $output.'/bundle');

            return [0, false];
        });

        $this->expectExceptionObject(JobOutputRefused::notAPlainFile('bundle'));

        try {
            $this->whenJobRuns($this->spec('build-1'));
        } finally {
            self::assertSame([], $this->engine->containerIds());
            self::assertDirectoryDoesNotExist($this->root.'/build-1');
        }
    }

    public function testAFolderInTheOutputIsRefused(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            mkdir($output.'/nested');

            return [0, false];
        });

        $this->expectExceptionObject(JobOutputRefused::notAPlainFile('nested'));

        $this->whenJobRuns($this->spec('build-1'));
    }

    public function testAContainerLeftByACrashedOrchestratorIsRemovedBeforeTheNextJob(): void
    {
        $orphan = $this->engine->leaveRunning('build-0');
        new Filesystem()->mkdir($this->root.'/build-0');

        $this->whenJobRuns($this->spec('build-1'));

        self::assertNotContains($orphan, $this->engine->containerIds());
        self::assertDirectoryDoesNotExist($this->root.'/build-0');
        self::assertSame([LedgerEventKind::JobContainerRemoved, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-0', 'container' => $orphan], $this->ledger->payloads()[0]);
    }

    public function testTheContainerAndOutputOfALivingRunAreLeftAlone(): void
    {
        $living = $this->engine->leaveRunning('build-0');
        new Filesystem()->mkdir($this->root.'/build-0');
        $holder = new LockFactory($this->locks)->createLock('intakt-job-build-0', ttl: null);
        self::assertTrue($holder->acquire());

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([$living], $this->engine->containerIds());
        self::assertDirectoryExists($this->root.'/build-0');
    }

    public function testASecondRunOfARunningJobIsRefused(): void
    {
        $holder = new LockFactory($this->locks)->createLock('intakt-job-build-1', ttl: null);
        self::assertTrue($holder->acquire());

        $this->expectExceptionObject(JobRefused::alreadyRunning(new JobId('build-1')));

        $this->whenJobRuns($this->spec('build-1'));
    }

    public function testAnUnreachableEngineFailsTheJob(): void
    {
        $this->engine->goDown();

        $this->expectException(DockerEngineFailed::class);
        $this->expectExceptionMessage('The Docker Engine is not reachable at '.FakeDockerEngine::SOCKET);

        $this->whenJobRuns($this->spec('build-1'));
    }

    public function testTheEngineIsCalledThroughItsSocket(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([FakeDockerEngine::SOCKET], $this->engine->sockets());
    }

    public function testAnEndTheLedgerDidNotRecordFailsTheJob(): void
    {
        $this->ledger = new InMemoryLedger(accepted: 0);

        $this->expectExceptionObject(JobEventNotRecorded::for('job_ended', 'the ledger is down'));

        $this->whenJobRuns($this->spec('build-1'));
    }

    /**
     * @param list<JobInput> $inputs
     */
    private function spec(string $job, array $inputs = []): JobSpec
    {
        return new JobSpec(new JobId($job), 'sample:latest', ['composer', 'test'], $inputs, new JobLimits(self::MEMORY, 1.0, 60));
    }

    private function whenJobRuns(JobSpec $spec): JobResult
    {
        $engine = new DockerEngine($this->engine->client(), FakeDockerEngine::SOCKET);
        $outputs = new JobOutputDirectories($this->root);
        $locks = new JobLocks(new LockFactory($this->locks));
        $ledger = new JobLedger($this->ledger);
        $runner = new ContainerJobRunner(
            $locks,
            new OrphanedJobs($engine, $outputs, $locks, $ledger),
            $outputs,
            new JobContainers($engine, new JobContainerDefinition()),
            $ledger,
        );

        return $runner->run($spec);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mountsOf(string $job): array
    {
        $host = $this->engine->definitionOf($job)['HostConfig'];
        self::assertIsArray($host);
        self::assertIsArray($host['Mounts']);

        /* @var list<array<string, mixed>> */
        return $host['Mounts'];
    }
}
