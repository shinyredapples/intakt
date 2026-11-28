<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\ImageVolumes;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobFollowedFile;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobKeptFolder;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Contract\JobWatch;
use Intakt\Core\Job\Infrastructure\ContainerEnd;
use Intakt\Core\Job\Infrastructure\ContainerHealth;
use Intakt\Core\Job\Infrastructure\ContainerJobRunner;
use Intakt\Core\Job\Infrastructure\ContainerState;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EgressLog;
use Intakt\Core\Job\Infrastructure\EgressProxy;
use Intakt\Core\Job\Infrastructure\EgressProxyDefinition;
use Intakt\Core\Job\Infrastructure\EgressRefusal;
use Intakt\Core\Job\Infrastructure\EgressRoute;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\JobContainer;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
use Intakt\Core\Job\Infrastructure\JobContainerRequest;
use Intakt\Core\Job\Infrastructure\JobContainers;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobNetwork;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use Intakt\Core\Job\Infrastructure\LiveOutputWatch;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Intakt\Core\Job\Infrastructure\StartCheck;
use Intakt\Core\Job\Infrastructure\StartCheckFailure;
use Intakt\Core\Job\Infrastructure\StartCheckLog;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ContainerJobRunner::class)]
#[CoversClass(JobContainers::class)]
#[CoversClass(LiveOutputWatch::class)]
#[CoversClass(JobContainerDefinition::class)]
#[UsesClass(JobKeptFolder::class)]
#[CoversClass(JobOutputDirectories::class)]
#[CoversClass(JobLedger::class)]
#[CoversClass(JobLocks::class)]
#[CoversClass(OrphanedJobs::class)]
#[CoversClass(DockerEngine::class)]
#[CoversClass(EgressProxy::class)]
#[CoversClass(EgressRoute::class)]
#[CoversClass(ContainerHealth::class)]
#[CoversClass(EngineAnswer::class)]
#[CoversClass(StartCheckLog::class)]
#[UsesClass(ContainerEnd::class)]
#[UsesClass(JobContainerRequest::class)]
#[UsesClass(StartCheck::class)]
#[UsesClass(StartCheckFailure::class)]
#[UsesClass(EgressProxyDefinition::class)]
#[UsesClass(EgressLog::class)]
#[UsesClass(EgressRefusal::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobNetwork::class)]
#[UsesClass(ContainerState::class)]
#[UsesClass(JobContainer::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(JobOutputFile::class)]
#[UsesClass(JobCommand::class)]
#[UsesClass(ImageVolumes::class)]
#[UsesClass(EgressHosts::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobRefused::class)]
#[UsesClass(JobOutputRefused::class)]
#[UsesClass(DockerEngineFailed::class)]
#[UsesClass(JobEventNotRecorded::class)]
final class ContainerJobRunnerTest extends TestCase
{
    private const int MEMORY = 512 * 1024 * 1024;
    private const string START_CHECK = __DIR__.'/../../../docker/job/start-check.sh';
    private const string FAILED_INPUT_CHECK = FakeContainerLogs::CHECK."input: /input/repository is not readable\n".FakeContainerLogs::CHECK."reduced mode: input\n";

    // Written as Squid's native access log writes a refused tunnel, so the run reads the proxy's real format.
    private const string REFUSED_TUNNEL = "1762855200.123      0 192.0.2.3 TCP_DENIED/403 3924 CONNECT outside.example:443 - HIER_NONE/- text/html\n";

    private string $root = '';
    private FakeDockerEngine $engine;
    private InMemoryLedger $ledger;
    private InMemoryStore $locks;

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

    public function testAJobLogsToAReadableDriverInBlockingModeWhateverTheDaemonDefaultIs(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['Type' => 'json-file', 'Config' => ['mode' => 'blocking', 'max-size' => '10m', 'max-file' => '3']], $this->hostConfigOf('build-1')['LogConfig']);
    }

    public function testAJobsLogIsCappedInSizeSoAChattyJobCannotFillTheDaemonsDisk(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['Type' => 'json-file', 'Config' => ['mode' => 'blocking', 'max-size' => '10m', 'max-file' => '3']], $this->hostConfigOf('build-1')['LogConfig'], 'Without max-size the json-file driver keeps every byte a job writes.');
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

    public function testAKeptFolderIsMountedWritableAfterTheOutputDirectoryAndNamedInTheEnvironment(): void
    {
        $this->whenJobRuns(new JobSpec(new JobId('install-1'), 'sample:latest', new JobCommand('true'), [], new JobLimits(self::MEMORY, 1.0, 60), JobEgress::none(), new JobKeptFolder('/srv/installs/1')));

        self::assertSame(
            ['Type' => 'bind', 'Source' => '/srv/installs/1', 'Target' => '/kept', 'ReadOnly' => false],
            array_last($this->mountsOf('install-1')),
        );
        $environment = $this->engine->definitionOf('install-1')['Env'];
        self::assertIsArray($environment);
        self::assertContains('INTAKT_JOB_KEPT=/kept', $environment);
    }

    public function testAJobWithoutAKeptFolderGetsNoKeptFolderVariable(): void
    {
        $this->whenJobRuns($this->spec('build-1'));

        $environment = $this->engine->definitionOf('build-1')['Env'];
        self::assertIsArray($environment);
        self::assertNotContains('INTAKT_JOB_KEPT=/kept', $environment);
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
        self::assertSame(['HOME=/work', 'TMPDIR=/work', 'INTAKT_JOB_ID=build-1', 'INTAKT_JOB_OUTPUT=/output'], $definition['Env']);
        self::assertSame(sprintf('%d:%d', posix_getuid(), posix_getgid()), $definition['User']);
    }

    public function testMemoryAndCpuLimitsAreSetOnTheJobsOwnContainer(): void
    {
        $this->whenJobRuns(new JobSpec(new JobId('build-1'), 'sample:latest', new JobCommand('true'), [], new JobLimits(self::MEMORY, 1.5, 60), JobEgress::none()));

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

        self::assertSame(['result.json'], $this->namesOf($result->output));
        self::assertSame('{"done":true}', $result->output->contents('result.json'));
        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
    }

    public function testAnOutputNamedLikeANumberKeepsItsNameAsText(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            file_put_contents($output.'/7', 'seven');

            return [0, false];
        });

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['7'], $this->namesOf($result->output));
        self::assertSame('seven', $result->output->contents('7'));
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

    public function testAWatchThatAsksToEndTheSessionSendsTheSessionSignalOnceAndTheJobStillRunsToItsOwnExit(): void
    {
        $this->engine->hang('build-1');
        $watch = new class implements JobWatch {
            public int $asked = 0;

            public function intervalSeconds(): int
            {
                return 5;
            }

            public function followedFile(): string
            {
                return 'build.log';
            }

            public function endsSession(JobFollowedFile $file): bool
            {
                return 2 <= ++$this->asked;
            }
        };

        $result = $this->whenJobRuns($this->spec('build-1'), watch: $watch);

        self::assertSame(['USR1'], $this->engine->signalsOf('build-1'));
        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
        self::assertSame(2, $watch->asked);
    }

    public function testAWatchThatNeverAsksToEndTheSessionLeavesTheJobToItsRunTime(): void
    {
        $this->engine->hang('build-1');
        $watch = new class implements JobWatch {
            public function intervalSeconds(): int
            {
                return 20;
            }

            public function followedFile(): string
            {
                return 'build.log';
            }

            public function endsSession(JobFollowedFile $file): bool
            {
                return false;
            }
        };

        $result = $this->whenJobRuns($this->spec('build-1'), watch: $watch);

        self::assertSame([], $this->engine->signalsOf('build-1'));
        self::assertEquals(new JobEnd(JobCause::TimedOut, 137), $result->end);
    }

    public function testAWatchReadsPiecesOfTheFileItFollowsWhileTheJobRuns(): void
    {
        $this->engine->hang('build-1');
        $watch = new class($this->root) implements JobWatch {
            /** @var list<string> */
            public array $seen = [];

            public function __construct(private readonly string $root)
            {
            }

            public function intervalSeconds(): int
            {
                return 5;
            }

            public function followedFile(): string
            {
                return 'session.log';
            }

            public function endsSession(JobFollowedFile $file): bool
            {
                file_put_contents($this->root.'/build-1/session.log', 'line one');
                $this->seen = [$file->read(0, 4)?->bytes ?? 'none', $file->read(5, 100)?->bytes ?? 'none'];

                return true;
            }
        };

        $this->whenJobRuns($this->spec('build-1'), watch: $watch);

        self::assertSame(['line', 'one'], $watch->seen);
    }

    public function testTheFileAWatchFollowsIsHandedBackByItsTailWhenItGrewPastTheOutputsBound(): void
    {
        $this->engine->hang('build-1');
        $watch = new readonly class($this->root) implements JobWatch {
            public function __construct(private string $root)
            {
            }

            public function intervalSeconds(): int
            {
                return 5;
            }

            public function followedFile(): string
            {
                return 'build.log';
            }

            public function endsSession(JobFollowedFile $file): bool
            {
                file_put_contents($this->root.'/build-1/build.log', str_repeat('x', JobOutputDirectories::MAX_BYTES)."the end\n");

                return true;
            }
        };

        $log = $this->whenJobRuns($this->spec('build-1'), watch: $watch)->output->contents('build.log') ?? '';

        self::assertSame(JobOutputDirectories::TAIL_BYTES, strlen($log));
        self::assertStringEndsWith("the end\n", $log);
    }

    public function testAMemoryKillJustBeforeTheRunTimeEndsIsRecordedAsTheJobsCause(): void
    {
        $this->engine->hang('build-1');
        $this->engine->memoryKillBeforeTheKill('build-1');

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::MemoryKilled, 137), $result->end);
        self::assertSame([['job' => 'build-1', 'cause' => 'oom', 'exit_code' => 137]], $this->ledger->payloads());
    }

    public function testAContainerThatDoesNotStopAfterTheKillFailsTheRunAndIsRemoved(): void
    {
        $this->engine->hang('build-1');
        $this->engine->ignoreKills('build-1');

        try {
            $this->whenJobRuns($this->spec('build-1'));
            self::fail('A container still running after the kill must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::notStoppedAfterKill('c0ffee000001', 10)->getMessage(), $failure->getMessage());
        }

        self::assertSame([], $this->ledger->kinds());
        self::assertSame([], $this->engine->containerIds());
    }

    public function testAWaitCutOffBeforeTheRunTimeIsWaitedForAgainAndTheJobIsNotKilled(): void
    {
        $this->engine->cutOffWaits('build-1', 1);

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
        self::assertSame([['job' => 'build-1', 'cause' => 'exited', 'exit_code' => 0]], $this->ledger->payloads());
    }

    public function testAJobWhoseWaitsKeepBreakingOffIsKilledOnlyWhenItsRunTimeIsOver(): void
    {
        $this->engine->cutOffWaits('build-1', PHP_INT_MAX);
        $started = $this->engine->clock->now();

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::TimedOut, 137), $result->end);
        self::assertSame(60, $this->engine->clock->now()->getTimestamp() - $started->getTimestamp());
    }

    public function testAWaitAnsweredWithAnErrorWhileTheJobRunsIsWaitedForAgainAndEndsTimedOut(): void
    {
        $this->engine->answerWaitsWithError('build-1');
        $started = $this->engine->clock->now();

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::TimedOut, 137), $result->end);
        self::assertSame(60, $this->engine->clock->now()->getTimestamp() - $started->getTimestamp());
        self::assertGreaterThan(1, $this->engine->waitsOf('build-1'));
        self::assertSame([['job' => 'build-1', 'cause' => 'timed_out', 'exit_code' => 137]], $this->ledger->payloads());
        self::assertSame([], $this->engine->containerIds());
    }

    public function testAnImageThatDeclaresVolumesIsRefusedBeforeAContainerIsCreated(): void
    {
        $this->engine->declareVolumes('sample:latest', ['/var/cache', '/data']);

        $this->expectExceptionObject(JobRefused::forImageVolumes('sample:latest', new ImageVolumes('/var/cache', '/data')));

        try {
            $this->whenJobRuns($this->spec('build-1'));
        } finally {
            self::assertFalse($this->engine->hasCreated('build-1'));
            self::assertDirectoryDoesNotExist($this->root.'/build-1');
        }
    }

    public function testAnOutputLargerThanTheLimitIsRefusedWithoutBeingRead(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            // Sparse and far above the memory limit, so reading it would end the process instead of failing this test.
            $bundle = fopen($output.'/bundle', 'w');
            self::assertNotFalse($bundle);
            ftruncate($bundle, 4 * 1024 * 1024 * 1024);
            fclose($bundle);

            return [0, false];
        });

        $this->expectExceptionObject(JobOutputRefused::tooLarge('bundle', JobOutputDirectories::MAX_BYTES));

        $this->whenJobRuns($this->spec('build-1'));
    }

    public function testAnOutputExactlyAtTheSizeLimitIsRead(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            $bundle = fopen($output.'/bundle', 'w');
            self::assertNotFalse($bundle);
            ftruncate($bundle, JobOutputDirectories::MAX_BYTES - 1);
            fclose($bundle);
            file_put_contents($output.'/result', 'x');

            return [0, false];
        });

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['bundle', 'result'], $this->namesOf($result->output));
        self::assertSame(JobOutputDirectories::MAX_BYTES - 1, strlen((string) $result->output->contents('bundle')));
    }

    public function testMoreOutputEntriesThanTheLimitAreRefused(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            for ($file = 0; $file <= JobOutputDirectories::MAX_FILES; ++$file) {
                touch(sprintf('%s/file-%04d', $output, $file));
            }

            return [0, false];
        });

        $this->expectExceptionObject(JobOutputRefused::tooMany(JobOutputDirectories::MAX_FILES));

        $this->whenJobRuns($this->spec('build-1'));
    }

    public function testAsManyOutputEntriesAsTheLimitAreRead(): void
    {
        $this->engine->behaveAs('build-1', static function (string $output): array {
            for ($file = 0; $file < JobOutputDirectories::MAX_FILES; ++$file) {
                touch(sprintf('%s/file-%04d', $output, $file));
            }

            return [0, false];
        });

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertCount(JobOutputDirectories::MAX_FILES, $result->output->files());
    }

    public function testAFolderLeftWithoutPermissionsIsRefusedAndRemovedAllTheSame(): void
    {
        $this->engine->behaveAs('evil', static function (string $output): array {
            mkdir($output.'/locked');
            touch($output.'/locked/kept');
            chmod($output.'/locked', 0o000);

            return [0, false];
        });

        try {
            $this->whenJobRuns($this->spec('evil'));
            self::fail('A folder in the output must be refused.');
        } catch (JobOutputRefused $refusal) {
            self::assertSame(JobOutputRefused::notAPlainFile('locked')->getMessage(), $refusal->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root.'/evil');
        self::assertEquals(new JobEnd(JobCause::Exited, 0), $this->whenJobRuns($this->spec('innocent'))->end);
    }

    public function testAFolderWithoutPermissionsLeftByACrashedJobDoesNotStopTheNextJob(): void
    {
        new Filesystem()->mkdir($this->root.'/build-0/locked/deeper');
        chmod($this->root.'/build-0/locked/deeper', 0o000);
        chmod($this->root.'/build-0/locked', 0o000);

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
        self::assertDirectoryDoesNotExist($this->root.'/build-0');
    }

    public function testALinkToAFolderInTheOutputIsNeverFollowedWhenPermissionsAreRestored(): void
    {
        // Beside the jobs root, because anything under it with a job-like name counts as a leftover output directory.
        $elsewhere = $this->root.'-elsewhere';
        new Filesystem()->mkdir($elsewhere);
        chmod($elsewhere, 0o500);
        $this->engine->behaveAs('build-1', static function (string $output) use ($elsewhere): array {
            symlink($elsewhere, $output.'/escape');

            return [0, false];
        });

        try {
            $this->whenJobRuns($this->spec('build-1'));
            self::fail('A link in the output must be refused.');
        } catch (JobOutputRefused) {
            self::assertSame(0o500, fileperms($elsewhere) & 0o777);
            self::assertDirectoryDoesNotExist($this->root.'/build-1');
        } finally {
            chmod($elsewhere, 0o700);
            rmdir($elsewhere);
        }
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

    public function testAContainerLeftByACrashedRunOfTheSameJobIsRemovedBeforeTheJobRunsAgain(): void
    {
        $orphan = $this->engine->leaveRunning('build-1');
        new Filesystem()->mkdir($this->root.'/build-1');

        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
        self::assertSame([], $this->engine->containerIds());
        self::assertSame([LedgerEventKind::JobContainerRemoved, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-1', 'container' => $orphan], $this->ledger->payloads()[0]);
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

        try {
            $this->whenJobRuns($this->spec('build-1'));
            self::fail('An unreachable engine must fail the job.');
        } catch (DockerEngineFailed $failure) {
            self::assertStringStartsWith('The Docker Engine is not reachable at '.FakeDockerEngine::SOCKET.': ', $failure->getMessage());
        }
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

    public function testACheckOrProbeJobGetsNoNetworkAndNoProxy(): void
    {
        $this->whenJobRuns($this->spec('check-1'));

        $definition = $this->engine->definitionOf('check-1');
        self::assertTrue($definition['NetworkDisabled']);
        self::assertSame('none', $this->hostConfigOf('check-1')['NetworkMode']);
        self::assertFalse($this->engine->hasCreatedProxy('check-1'));
        self::assertSame([], $this->engine->networkNames());
    }

    public function testAJobWithAnAllowlistIsOnlyOnItsOwnInternalNetworkWithItsProxy(): void
    {
        $this->engine->behaveAs('build-1', function (string $output): array {
            self::assertSame(['Name' => 'intakt-job-build-1', 'Internal' => true, 'EnableIPv6' => false, 'Options' => ['com.docker.network.bridge.inhibit_ipv4' => 'true'], 'Labels' => ['intakt.job' => 'build-1']], $this->engine->networkOf('intakt-job-build-1'));

            return [0, false];
        });

        $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        $definition = $this->engine->definitionOf('build-1');
        self::assertFalse($definition['NetworkDisabled']);
        self::assertSame('intakt-job-build-1', $this->hostConfigOf('build-1')['NetworkMode']);
        self::assertArrayNotHasKey('NetworkingConfig', $definition);
        self::assertIsArray($definition['Env']);
        self::assertContains('HTTPS_PROXY=http://egress-proxy:3128', $definition['Env']);
        self::assertContains('https_proxy=http://egress-proxy:3128', $definition['Env']);
    }

    public function testTheStartCheckRunsBeforeTheJobsCommandAndChecksItsInputs(): void
    {
        $this->whenJobRuns($this->spec('build-1', [new JobInput('/srv/mirror/sample.git', '/input/repository')]));

        $definition = $this->engine->definitionOf('build-1');
        self::assertIsArray($definition['Entrypoint']);
        self::assertSame(['/bin/sh', '-c', file_get_contents(self::START_CHECK), 'intakt-start-check'], array_slice($definition['Entrypoint'], 0, 4));
        self::assertSame(['/input/repository', '--', 'composer', 'test'], $definition['Cmd']);
    }

    public function testEachRunGivesTheStartCheckANewMarkOutsideTheJobsEnvironment(): void
    {
        $this->whenJobRuns($this->spec('build-1'));
        $this->whenJobRuns($this->spec('build-2'));

        $first = $this->markOf('build-1');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $first);
        self::assertNotSame($first, $this->markOf('build-2'));
        self::assertIsArray($this->engine->definitionOf('build-1')['Env']);
        self::assertSame([], preg_grep('/'.$first.'/', $this->engine->definitionOf('build-1')['Env']));
    }

    public function testAJobWithAnAllowlistIsToldWhichHostsTheStartCheckTries(): void
    {
        $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example', 'repo.packagist.example'))));

        $definition = $this->engine->definitionOf('build-1');
        self::assertIsArray($definition['Env']);
        self::assertContains('INTAKT_JOB_EGRESS=api.vendor.example repo.packagist.example', $definition['Env']);
    }

    public function testAPassedStartCheckRecordsOnlyTheEndAndLeavesNothingInTheOutput(): void
    {
        $result = $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame([], $result->output->files());
    }

    public function testABrokenMountTheStartCheckLogsIsRecordedWithItsReasonBeforeTheEnd(): void
    {
        $this->engine->jobStandardError('build-1', self::FAILED_INPUT_CHECK);

        $this->whenJobRuns($this->spec('build-1', [new JobInput('/srv/mirror/sample.git', '/input/repository')]));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-1', 'check' => 'input', 'reason' => '/input/repository is not readable'], $this->ledger->payloads()[0]);
    }

    public function testAJobThatRewritesTheReportFileAsAPassAfterAFailedCheckStillRecordsTheFailure(): void
    {
        $this->engine->jobStandardError('build-1', self::FAILED_INPUT_CHECK);
        $this->engine->behaveAs('build-1', static function (string $output): array {
            file_put_contents($output.'/.intakt-start-check', "end\n");

            return [0, false];
        });

        $this->whenJobRuns($this->spec('build-1', [new JobInput('/srv/mirror/sample.git', '/input/repository')]));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame('input', $this->ledger->payloads()[0]['check'] ?? null);
    }

    public function testAJobThatPrintsAForgedFullModeLineAfterAFailedCheckStillRecordsTheFailure(): void
    {
        $this->engine->jobStandardError('build-1', self::FAILED_INPUT_CHECK."intakt-start-check: full mode\n");

        $this->whenJobRuns($this->spec('build-1', [new JobInput('/srv/mirror/sample.git', '/input/repository')]));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-1', 'check' => 'input', 'reason' => '/input/repository is not readable'], $this->ledger->payloads()[0]);
    }

    // Played without the check's lines, because a rotating log driver drops them after a flood and leaves a head that is only the job's.
    public function testAJobWhoseCheckLinesWereRotatedAwayCannotPassWithForgedLines(): void
    {
        $this->engine->jobStandardError('build-1', "intakt-start-check: output: ok\nintakt-start-check: full mode\n".str_repeat("job noise\n", 10));

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame('log', $this->ledger->payloads()[0]['check'] ?? null);
    }

    public function testAJobWhoseLogHoldsNoModeLineIsRecordedAsAFailedCheck(): void
    {
        $this->engine->jobStandardError('build-1', '');

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame('log', $this->ledger->payloads()[0]['check'] ?? null);
    }

    public function testAModeLineBeyondTheFirst64KibOfTheLogIsNotRead(): void
    {
        $this->engine->jobStandardError('build-1', str_repeat(str_repeat('x', 1023)."\n", 64).FakeContainerLogs::CHECK."full mode\n");

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([LedgerEventKind::JobStartCheckFailed, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame('log', $this->ledger->payloads()[0]['check'] ?? null);
    }

    public function testAMissingStartCheckScriptFailsTheJobBeforeItsContainerIsCreated(): void
    {
        try {
            $this->whenJobRuns($this->spec('build-1'), $this->root.'/absent.sh');
            self::fail('The job ran without its start check.');
        } catch (IOException $failure) {
            self::assertStringContainsString($this->root.'/absent.sh', $failure->getMessage());
        }

        self::assertFalse($this->engine->hasCreated('build-1'));
        self::assertSame([], $this->ledger->kinds());
    }

    public function testTheProxyIsOnTheJobsNetworkAndTheBridgeAndAllowsOnlyTheJobsHosts(): void
    {
        $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example', 'repo.packagist.org'))));

        $proxy = $this->engine->proxyDefinitionOf('build-1');
        self::assertSame('intakt-egress-proxy', $proxy['Image']);
        self::assertSame(['EndpointsConfig' => ['bridge' => [], 'intakt-job-build-1' => ['Aliases' => ['egress-proxy']]]], $proxy['NetworkingConfig']);
        self::assertIsArray($proxy['Env']);
        self::assertIsString($proxy['Env'][0] ?? null);
        self::assertStringContainsString("\nacl intakt_allowed dstdomain -n api.vendor.example repo.packagist.org\n", $proxy['Env'][0]);
    }

    public function testAJobStartsOnlyOnceItsProxyIsHealthy(): void
    {
        $this->engine->proxyHealth('build-1', 'starting', 'starting', 'healthy');
        $started = $this->engine->clock->now();
        $this->engine->behaveAs('build-1', function (string $output) use ($started): array {
            self::assertEqualsWithDelta(0.5, (float) $this->engine->clock->now()->format('U.u') - (float) $started->format('U.u'), 0.001);

            return [0, false];
        });

        $result = $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
    }

    public function testAProxyThatStopsBeforeItIsHealthyFailsTheRunBeforeTheJobIsCreated(): void
    {
        $this->engine->proxyHealth('build-1', 'starting', 'exited');

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A proxy that stopped must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::proxyNotReady('intakt-egress-build-1', 'it stopped')->getMessage(), $failure->getMessage());
            self::assertFalse($this->engine->hasCreated('build-1'));
            self::assertSame([], $this->engine->containerIds());
            self::assertSame([], $this->engine->networkNames());
            self::assertSame([], $this->ledger->kinds());
        }
    }

    public function testAnUnhealthyProxyFailsTheRunBeforeTheJobIsCreated(): void
    {
        $this->engine->proxyHealth('build-1', 'unhealthy');

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('An unhealthy proxy must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::proxyNotReady('intakt-egress-build-1', 'it is unhealthy')->getMessage(), $failure->getMessage());
            self::assertFalse($this->engine->hasCreated('build-1'));
            self::assertSame([], $this->engine->containerIds());
        }
    }

    public function testAProxyStillStartingAfterThirtySecondsFailsTheRunBeforeTheJobIsCreated(): void
    {
        $this->engine->proxyHealth('build-1', 'starting');
        $started = $this->engine->clock->now();

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A proxy that never gets healthy must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::proxyNotReady('intakt-egress-build-1', 'it was still starting after 30 seconds')->getMessage(), $failure->getMessage());
            self::assertSame(30, $this->engine->clock->now()->getTimestamp() - $started->getTimestamp());
            self::assertFalse($this->engine->hasCreated('build-1'));
            self::assertSame([], $this->engine->containerIds());
            self::assertSame([], $this->engine->networkNames());
        }
    }

    public function testAProxyWithoutAHealthCheckStatusFailsTheRunBeforeTheJobIsCreated(): void
    {
        $this->engine->proxyHealth('build-1', 'none');

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A proxy without a health check status must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::unexpected('containers/json')->getMessage(), $failure->getMessage());
            self::assertFalse($this->engine->hasCreated('build-1'));
            self::assertSame([], $this->engine->networkNames());
        }
    }

    public function testARunningProxyWhoseHealthStatusReadsStoppedFailsTheRunBeforeTheJobIsCreated(): void
    {
        $this->engine->proxyHealth('build-1', 'stopped');

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A health status the engine does not report must fail the run.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::unexpected('containers/json')->getMessage(), $failure->getMessage());
            self::assertFalse($this->engine->hasCreated('build-1'));
        }
    }

    public function testAProxyHealthyJustAtItsDeadlineLetsTheJobStart(): void
    {
        $this->engine->proxyHealth('build-1', ...[...array_fill(0, 120, 'starting'), 'healthy']);

        $result = $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
    }

    public function testARefusalFromTheProxysLogIsRecordedBeforeTheJobsEnd(): void
    {
        $this->engine->proxyLogs('build-1', self::REFUSED_TUNNEL);

        $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertSame([LedgerEventKind::JobEgressRefused, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-1', 'method' => 'CONNECT', 'target' => 'outside.example:443'], $this->ledger->payloads()[0]);
    }

    public function testTheProxyAndTheNetworkAreRemovedAfterTheJob(): void
    {
        $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertTrue($this->engine->hasCreatedProxy('build-1'));
        self::assertSame([], $this->engine->containerIds());
        self::assertSame([], $this->engine->networkNames());
    }

    public function testTheProxyAndTheNetworkAreRemovedWhenTheJobFails(): void
    {
        $this->engine->garbleState();

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A garbled state must fail the job.');
        } catch (DockerEngineFailed) {
            self::assertSame([], $this->engine->containerIds());
            self::assertSame([], $this->engine->networkNames());
        }
    }

    public function testAProxyLogTheEngineFramedWronglyFailsTheJobAndLeavesNothingBehind(): void
    {
        $this->engine->proxyLogs('build-1', '');
        $this->engine->breakLogFrames('build-1');

        try {
            $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));
            self::fail('A broken log frame must fail the job.');
        } catch (DockerEngineFailed $failure) {
            self::assertSame(DockerEngineFailed::unexpected('containers/intakt-egress-build-1/logs')->getMessage(), $failure->getMessage());
            self::assertSame([], $this->engine->containerIds());
            self::assertSame([], $this->engine->networkNames());
        }
    }

    public function testTheProxyAndNetworkOfACrashedRunOfTheSameJobAreRemovedBeforeItRunsAgain(): void
    {
        $proxy = $this->engine->leaveRunning('build-1', 'intakt-egress-');
        $this->engine->leaveNetwork('build-1');

        $result = $this->whenJobRuns($this->spec('build-1', egress: JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertEquals(new JobEnd(JobCause::Exited, 0), $result->end);
        self::assertSame([LedgerEventKind::JobContainerRemoved, LedgerEventKind::JobNetworkRemoved, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => 'build-1', 'container' => $proxy], $this->ledger->payloads()[0]);
        self::assertSame(['job' => 'build-1', 'network' => 'intakt-job-build-1'], $this->ledger->payloads()[1]);
        self::assertSame([], $this->engine->networkNames());
    }

    public function testTheNetworkOfALivingRunIsLeftAlone(): void
    {
        $this->engine->leaveNetwork('build-0');
        $holder = new LockFactory($this->locks)->createLock('intakt-job-build-0', ttl: null);
        self::assertTrue($holder->acquire());

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame(['intakt-job-build-0'], $this->engine->networkNames());
    }

    public function testTheNetworkOfAGoneRunIsRemoved(): void
    {
        $this->engine->leaveNetwork('build-0');

        $this->whenJobRuns($this->spec('build-1'));

        self::assertSame([], $this->engine->networkNames());
        self::assertSame([LedgerEventKind::JobNetworkRemoved, LedgerEventKind::JobEnded], $this->ledger->kinds());
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-jobs-'.bin2hex(random_bytes(4));
        $this->engine = new FakeDockerEngine();
        $this->ledger = new InMemoryLedger();
        $this->locks = new InMemoryStore();
    }

    // Permissions are restored first, because a failing or interrupted test can leave a folder without any, and the next build could not empty its temporary folder.
    protected function tearDown(): void
    {
        $this->restoreOwnerAccess($this->root);
        new Filesystem()->remove($this->root);
    }

    /**
     * @param list<JobInput> $inputs
     */
    private function spec(string $job, array $inputs = [], ?JobEgress $egress = null): JobSpec
    {
        return new JobSpec(new JobId($job), 'sample:latest', new JobCommand('composer', 'test'), $inputs, new JobLimits(self::MEMORY, 1.0, 60), $egress ?? JobEgress::none());
    }

    /**
     * @return list<string>
     */
    private function namesOf(JobOutput $output): array
    {
        return array_map(static fn (JobOutputFile $file): string => $file->name, $output->files());
    }

    /**
     * @return array<string, mixed>
     */
    private function hostConfigOf(string $job): array
    {
        /** @var array<string, mixed> $host */
        $host = $this->engine->definitionOf($job)['HostConfig'];

        return $host;
    }

    private function markOf(string $job): string
    {
        $entrypoint = $this->engine->definitionOf($job)['Entrypoint'];
        self::assertIsArray($entrypoint);
        self::assertIsString($entrypoint[4] ?? null);

        return $entrypoint[4];
    }

    private function whenJobRuns(JobSpec $spec, string $startCheck = self::START_CHECK, ?JobWatch $watch = null): JobResult
    {
        $engine = new DockerEngine($this->engine->client(), FakeDockerEngine::SOCKET);
        $outputs = new JobOutputDirectories($this->root);
        $locks = new JobLocks(new LockFactory($this->locks));
        $ledger = new JobLedger($this->ledger);
        $runner = new ContainerJobRunner(
            $locks,
            new OrphanedJobs($engine, $outputs, $locks, $ledger),
            $outputs,
            new JobContainers(
                $engine,
                new JobContainerDefinition($startCheck),
                new EgressProxy($engine, new EgressProxyDefinition('intakt-egress-proxy'), new EgressLog(), $ledger, $this->engine->clock),
                $this->engine->clock,
            ),
            $ledger,
            new StartCheckLog(),
        );

        return $runner->run($spec, $watch);
    }

    private function restoreOwnerAccess(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            return;
        }

        chmod($path, 0o700);

        $names = scandir($path);

        foreach (array_diff(false === $names ? [] : $names, ['.', '..']) as $name) {
            $this->restoreOwnerAccess($path.'/'.$name);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mountsOf(string $job): array
    {
        $host = $this->engine->definitionOf($job)['HostConfig'];
        self::assertIsArray($host);
        /** @var list<array<string, mixed>> $mounts */
        $mounts = $host['Mounts'];

        return $mounts;
    }
}
