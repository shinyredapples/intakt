<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\InvalidJobSpec;
use Intakt\Core\Job\Contract\JobEndCause;
use Intakt\Core\Job\Contract\JobOutcome;
use Intakt\Core\Job\Contract\StartCheckItem;
use Intakt\Core\Job\Contract\StartCheckReport;
use Intakt\Core\Job\Infrastructure\DockerEngineApi;
use Intakt\Core\Job\Infrastructure\DockerEngineError;
use Intakt\Core\Job\Infrastructure\DockerJobRunner;
use Intakt\Core\Job\Infrastructure\EgressProxy;
use Intakt\Core\Job\Infrastructure\JobContainerBody;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobResources;
use Intakt\Core\Job\Infrastructure\StartCheckReader;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DockerJobRunner::class)]
#[CoversClass(JobContainerBody::class)]
#[CoversClass(JobResources::class)]
#[CoversClass(JobLedger::class)]
#[CoversClass(JobLocks::class)]
#[CoversClass(JobOutcome::class)]
#[UsesClass(DockerEngineApi::class)]
#[UsesClass(DockerEngineError::class)]
#[UsesClass(EgressProxy::class)]
#[UsesClass(StartCheckReader::class)]
#[UsesClass(StartCheckReport::class)]
#[UsesClass(StartCheckItem::class)]
final class DockerJobRunnerTest extends TestCase
{
    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-job-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder.'/out');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testAJobContainerHasAReadOnlyRootATmpfsHomeAndItsInputsMountedReadOnly(): void
    {
        $harness = new JobHarness();

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->withInput('/srv/checkout', '/checkout')->build());

        $host = $harness->engine->hostConfig();
        self::assertTrue($host['ReadonlyRootfs']);
        self::assertArrayHasKey('/home/job', $host['Tmpfs']);
        self::assertArrayHasKey('/work', $host['Tmpfs']);
        self::assertContains(['Type' => 'bind', 'Source' => '/srv/checkout', 'Target' => '/checkout', 'ReadOnly' => true], $host['Mounts']);
    }

    public function testTheOnlyWritableMountIsTheJobsOwnOutputDirectory(): void
    {
        $harness = new JobHarness();
        $spec = JobSpecBuilder::in($this->folder.'/out')->withInput('/srv/checkout', '/checkout')->build();

        $harness->runner()->run($spec);

        $writable = array_values(array_filter($harness->engine->hostConfig()['Mounts'], static fn (array $mount): bool => false === $mount['ReadOnly']));
        self::assertSame([['Type' => 'bind', 'Source' => $this->folder.'/out', 'Target' => '/output', 'ReadOnly' => false]], $writable);
    }

    public function testAJobHasMemoryAndCpuLimitsNoSwapNoCapabilitiesAndNoPrivilegeGain(): void
    {
        $harness = new JobHarness();

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        $host = $harness->engine->hostConfig();
        self::assertSame(512 * 1024 * 1024, $host['Memory']);
        self::assertSame($host['Memory'], $host['MemorySwap']);
        self::assertSame(1_500_000_000, $host['NanoCpus']);
        self::assertSame(['ALL'], $host['CapDrop']);
        self::assertSame(['no-new-privileges'], $host['SecurityOpt']);
    }

    public function testACheckJobGetsNetworkModeNoneAndNoProxy(): void
    {
        $harness = new JobHarness();

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->withoutEgress()->build());

        self::assertSame('none', $harness->engine->hostConfig()['NetworkMode']);
        self::assertSame([], $harness->engine->networks);
        self::assertSame(['intakt-job-build-1'], array_keys($harness->engine->created));
        self::assertEmpty(preg_grep('/PROXY/i', $harness->engine->environment()));
    }

    public function testABuildJobsNetworkIsInternalAndItReachesOutOnlyThroughItsProxy(): void
    {
        $harness = new JobHarness();
        $seen = ['networks' => [], 'names' => []];
        $harness->engine->onJobStart = function () use ($harness, &$seen): void {
            $seen = ['networks' => $harness->engine->networks, 'names' => $harness->engine->namesOfContainers()];
        };

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame(['intakt-job-build-1'], array_column($seen['networks'], 'name'));
        self::assertContains('intakt-job-build-1-proxy', $seen['names']);
        self::assertContains('POST /networks/n1/connect', $harness->engine->calls);
    }

    public function testABuildJobsEnvironmentPointsAtItsProxyAndNamesTheVendor(): void
    {
        $harness = new JobHarness();

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        $environment = $harness->engine->environment();
        self::assertContains('HTTPS_PROXY=http://proxy:8888', $environment);
        self::assertContains('INTAKT_VENDOR_HOST=api.vendor.example', $environment);
        self::assertSame('n1', $harness->engine->hostConfig()['NetworkMode']);
    }

    public function testAJobsEnvironmentCarriesNoForgeTokenSigningKeyOrVendorKeyEvenWhenTheOrchestratorHoldsThem(): void
    {
        putenv('GITHUB_APP_PRIVATE_KEY=orchestrator-private-key');
        putenv('ANTHROPIC_API_KEY=orchestrator-vendor-key');
        $harness = new JobHarness();

        try {
            $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());
        } finally {
            putenv('GITHUB_APP_PRIVATE_KEY');
            putenv('ANTHROPIC_API_KEY');
        }

        $names = array_map(static fn (string $line): string => explode('=', $line)[0], $harness->engine->environment());
        self::assertSame(['HOME', 'INTAKT_JOB_ID', 'INTAKT_OUTPUT_DIR', 'INTAKT_INPUTS', 'INTAKT_VENDOR_HOST', 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'], $names);
    }

    public function testAContainerKilledForMemoryEndsWithTheCauseMemoryKilledInTheLedger(): void
    {
        $harness = new JobHarness();
        $harness->engine->exitCode = 137;
        $harness->engine->oomKilled = true;

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame(JobEndCause::MemoryKilled, $outcome->cause);
        self::assertSame([LedgerEventKind::JobEnded], $harness->ledger->kinds());
        self::assertSame('memory_killed', $harness->ledger->payloads()[0]['cause']);
        self::assertSame('build-1', $harness->ledger->payloads()[0]['job_id']);
    }

    public function testAnExit137WithoutTheEnginesMemoryFlagIsNotAMemoryKill(): void
    {
        $harness = new JobHarness();
        $harness->engine->exitCode = 137;

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame(JobEndCause::Exited, $outcome->cause);
        self::assertSame(137, $outcome->exitCode);
    }

    public function testAJobThatOutlastsItsTimeLimitIsKilledAndEndsTimedOut(): void
    {
        $harness = new JobHarness();
        $harness->engine->timesOut = true;

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame(JobEndCause::TimedOut, $outcome->cause);
        self::assertContains('POST /containers/c3/kill', $harness->engine->calls);
        self::assertSame([], $harness->engine->containers);
    }

    public function testTheContainerItsProxyAndItsNetworkAreRemovedAfterExit(): void
    {
        $harness = new JobHarness();

        $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame([], $harness->engine->containers);
        self::assertSame([], $harness->engine->networks);
    }

    public function testTheContainerItsProxyAndItsNetworkAreRemovedWhenTheJobFailsToStart(): void
    {
        $harness = new JobHarness();
        $harness->engine->refusesToStartTheJob = true;

        try {
            $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());
            self::fail('The failed start should have thrown.');
        } catch (RuntimeException) {
            // The failed start is expected here, because the cleanup after it is what this test asserts.
        }

        self::assertSame([], $harness->engine->containers);
        self::assertSame([], $harness->engine->networks);
    }

    public function testAJobIsNotStartedTwiceWhileItsFirstRunHoldsTheLock(): void
    {
        $harness = new JobHarness();
        $held = $harness->locks->claim('build-1');
        $this->expectException(InvalidJobSpec::class);

        try {
            $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());
        } finally {
            self::assertNotNull($held);
            self::assertSame([], $harness->engine->containers);
        }
    }

    public function testAJobWhoseEndTheLedgerRefusesFailsLoudlyAfterItsCleanup(): void
    {
        $harness = new JobHarness(0);

        try {
            $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());
            self::fail('The refused append should have thrown.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('was not recorded', $failure->getMessage());
        }

        self::assertSame([], $harness->engine->containers);
    }

    public function testAMissingOutputDirectoryIsRefusedBeforeAnythingIsCreated(): void
    {
        $harness = new JobHarness();

        try {
            $harness->runner()->run(JobSpecBuilder::in($this->folder.'/absent')->build());
            self::fail('The missing directory should have been refused.');
        } catch (InvalidJobSpec) {
            self::assertSame([], $harness->engine->calls);
        }
    }

    public function testABrokenInputMountIsReportedByTheStartCheckAndTheJobRunsInReducedMode(): void
    {
        $harness = new JobHarness();
        $harness->engine->onJobStart = fn () => file_put_contents($this->folder.'/out/.start-check', "output\tok\t\ninput:/checkout\tfail\tthe mount is missing or unreadable\n");

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->withoutEgress()->withInput('/srv/checkout', '/checkout')->build());

        self::assertTrue($outcome->startCheck->reduced());
        self::assertSame('input:/checkout', $outcome->startCheck->failed()[0]->name);
        self::assertTrue($harness->ledger->payloads()[0]['reduced']);
    }

    public function testAJobThatReportedNoFailureIsNotInReducedMode(): void
    {
        $harness = new JobHarness();
        $harness->engine->onJobStart = fn () => file_put_contents($this->folder.'/out/.start-check', "output\tok\t\nvendor\tok\t\n");

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertFalse($outcome->startCheck->reduced());
    }

    public function testRefusalsTheProxyLoggedAreHandedBackInTheOutcome(): void
    {
        $harness = new JobHarness();
        $harness->engine->proxyLog = "INFO Connect (file descriptor 7): job\nNOTICE Proxying refused on filtered domain \"evil.example\"\n";

        $outcome = $harness->runner()->run(JobSpecBuilder::in($this->folder.'/out')->build());

        self::assertSame(['NOTICE Proxying refused on filtered domain "evil.example"'], $outcome->egressRefusals);
    }
}
