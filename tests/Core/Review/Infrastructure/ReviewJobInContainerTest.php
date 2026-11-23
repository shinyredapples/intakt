<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\ContainerJobRunner;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EgressLog;
use Intakt\Core\Job\Infrastructure\EgressProxy;
use Intakt\Core\Job\Infrastructure\EgressProxyDefinition;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
use Intakt\Core\Job\Infrastructure\JobContainerRequest;
use Intakt\Core\Job\Infrastructure\JobContainers;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Intakt\Core\Job\Infrastructure\StartCheckLog;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use Intakt\Core\Review\Infrastructure\ClaudeCodeJobEvents;
use Intakt\Core\Review\Infrastructure\ReportedUsage;
use Intakt\Core\Review\Infrastructure\ReviewJob;
use Intakt\Core\Review\Infrastructure\ReviewJobAnswer;
use Intakt\Core\Review\Infrastructure\ReviewJobImages;
use Intakt\Tests\Core\Job\FakeDockerEngine;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The review job through the container runner, its engine client and its egress proxy, with only the Docker daemon scripted, because the build sandbox has none.
#[CoversClass(ReviewJob::class)]
#[UsesClass(ReviewJobAnswer::class)]
#[UsesClass(ClaudeCodeJobEvents::class)]
#[UsesClass(ReportedUsage::class)]
#[UsesClass(ReviewJobImages::class)]
#[UsesClass(ReviewJobRequest::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(ContainerJobRunner::class)]
#[UsesClass(DockerEngine::class)]
#[UsesClass(EgressLog::class)]
#[UsesClass(EgressProxy::class)]
#[UsesClass(EgressProxyDefinition::class)]
#[UsesClass(JobContainerDefinition::class)]
#[UsesClass(JobContainerRequest::class)]
#[UsesClass(JobContainers::class)]
#[UsesClass(JobLedger::class)]
#[UsesClass(JobLocks::class)]
#[UsesClass(JobOutputDirectories::class)]
#[UsesClass(OrphanedJobs::class)]
#[UsesClass(StartCheckLog::class)]
final class ReviewJobInContainerTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../docker/job/review.sh';

    private const string START_CHECK = __DIR__.'/../../../../docker/job/start-check.sh';

    private const string HEAD = '0123456789abcdef0123456789abcdef01234567';

    // Written as Squid's native access log writes a refused tunnel, so the run reads the proxy's real format.
    private const string REFUSED_TUNNEL = "1762855200.123      0 192.0.2.3 TCP_DENIED/403 3924 CONNECT outside.example:443 - HIER_NONE/- text/html\n";

    private string $folder = '';

    private FakeDockerEngine $engine;

    private InMemoryLedger $ledger;

    private ?JobSpec $ran = null;

    /** @var array<string, mixed> */
    private array $networkWhileRunning = [];

    public function testTheReviewerGetsTheCheckoutOfTheHeadAsAReadOnlyMount(): void
    {
        $answer = $this->reviewInContainer();

        self::assertSame('{"verdict":"approve"}', $answer->text);
        $mounts = $this->hostConfig()['Mounts'];
        self::assertIsArray($mounts);
        self::assertContains(
            ['Type' => 'bind', 'Source' => $this->folder.'/checkout', 'Target' => ReviewJob::CHECKOUT, 'ReadOnly' => true],
            $mounts,
        );
        self::assertTrue($this->hostConfig()['ReadonlyRootfs']);
    }

    public function testTheReviewerIsOnlyOnAnInternalNetworkWhoseProxyAllowsItsVendorAlone(): void
    {
        $this->reviewInContainer();

        self::assertNotNull($this->ran);
        self::assertTrue($this->networkWhileRunning['Internal']);
        self::assertSame('intakt-job-'.$this->ran->id->value, $this->hostConfig()['NetworkMode']);
        $proxy = $this->engine->proxyDefinitionOf($this->ran->id->value);
        self::assertIsArray($proxy['Env']);
        $squid = $proxy['Env'][0];
        self::assertIsString($squid);
        self::assertStringContainsString("\nacl intakt_allowed dstdomain -n api.anthropic.com\n", $squid);
        self::assertStringContainsString("\nhttp_access deny all\n", $squid);
    }

    public function testATunnelToAnotherHostIsRefusedByTheProxyAndRecorded(): void
    {
        $this->reviewInContainer(self::REFUSED_TUNNEL);

        self::assertNotNull($this->ran);
        self::assertSame([LedgerEventKind::JobEgressRefused, LedgerEventKind::JobEnded], $this->ledger->kinds());
        self::assertSame(['job' => $this->ran->id->value, 'method' => 'CONNECT', 'target' => 'outside.example:443'], $this->ledger->payloads()[0]);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-review-container-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/checkout');
        $this->engine = new FakeDockerEngine();
        $this->ledger = new InMemoryLedger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function reviewInContainer(string $proxyLog = ''): ReviewJobAnswer
    {
        $container = $this->containerRunner();
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($container, $proxyLog) {
            $this->ran = $spec;
            $this->engine->proxyLogs($spec->id->value, $proxyLog);
            $this->engine->behaveAs($spec->id->value, function (string $output) use ($spec): array {
                $this->networkWhileRunning = $this->engine->networkOf('intakt-job-'.$spec->id->value);
                file_put_contents($output.'/'.ReviewJob::ANSWER, '{"type":"result","subtype":"success","is_error":false,"result":"{\"verdict\":\"approve\"}"}');
                file_put_contents($output.'/'.ReviewJob::LOG, "claude_code exited with 0\n");

                return [0, false];
            });

            return $container->run($spec);
        });

        $review = new ReviewJob(new ReviewJobImages(['demo' => 'intakt-job/demo:review']), $runner, new Filesystem(), self::SCRIPT, $this->folder.'/prompts');

        return $review->run(new ReviewJobRequest('demo', $this->folder.'/checkout', self::HEAD, 'correctness', new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'), 'PLANT-prompt'));
    }

    /**
     * @return array<string, mixed>
     */
    private function hostConfig(): array
    {
        self::assertNotNull($this->ran);
        /** @var array<string, mixed> $host */
        $host = $this->engine->definitionOf($this->ran->id->value)['HostConfig'];

        return $host;
    }

    private function containerRunner(): ContainerJobRunner
    {
        $engine = new DockerEngine($this->engine->client(), FakeDockerEngine::SOCKET);
        $outputs = new JobOutputDirectories($this->folder.'/jobs');
        $locks = new JobLocks(new LockFactory(new InMemoryStore()));
        $ledger = new JobLedger($this->ledger);

        return new ContainerJobRunner(
            $locks,
            new OrphanedJobs($engine, $outputs, $locks, $ledger),
            $outputs,
            new JobContainers(
                $engine,
                new JobContainerDefinition(self::START_CHECK),
                new EgressProxy($engine, new EgressProxyDefinition('intakt-egress-proxy'), new EgressLog(), $ledger, $this->engine->clock),
                $this->engine->clock,
            ),
            $ledger,
            new StartCheckLog(),
        );
    }
}
