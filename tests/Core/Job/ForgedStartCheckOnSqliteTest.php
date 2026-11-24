<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\ContainerEnd;
use Intakt\Core\Job\Infrastructure\ContainerJobRunner;
use Intakt\Core\Job\Infrastructure\ContainerState;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EgressLog;
use Intakt\Core\Job\Infrastructure\EgressProxy;
use Intakt\Core\Job\Infrastructure\EgressProxyDefinition;
use Intakt\Core\Job\Infrastructure\EgressRoute;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
use Intakt\Core\Job\Infrastructure\JobContainerRequest;
use Intakt\Core\Job\Infrastructure\JobContainers;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Intakt\Core\Job\Infrastructure\StartCheck;
use Intakt\Core\Job\Infrastructure\StartCheckFailure;
use Intakt\Core\Job\Infrastructure\StartCheckLog;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The install's own ledger on SQLite records the start check, so the forged pass is shown failing where the dashboard reads it, not only in memory.
#[Group('persistence')]
#[CoversClass(ContainerJobRunner::class)]
#[CoversClass(StartCheckLog::class)]
#[UsesClass(JobLedger::class)]
#[UsesClass(JobContainers::class)]
#[UsesClass(JobContainerDefinition::class)]
#[UsesClass(JobOutputDirectories::class)]
#[UsesClass(JobLocks::class)]
#[UsesClass(OrphanedJobs::class)]
#[UsesClass(DockerEngine::class)]
#[UsesClass(EngineAnswer::class)]
#[UsesClass(EgressProxy::class)]
#[UsesClass(EgressProxyDefinition::class)]
#[UsesClass(EgressLog::class)]
#[UsesClass(EgressRoute::class)]
#[UsesClass(ContainerEnd::class)]
#[UsesClass(JobContainerRequest::class)]
#[UsesClass(ContainerState::class)]
#[UsesClass(StartCheck::class)]
#[UsesClass(StartCheckFailure::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(JobResult::class)]
final class ForgedStartCheckOnSqliteTest extends KernelTestCase
{
    private const string START_CHECK = __DIR__.'/../../../docker/job/start-check.sh';

    private string $root = '';

    public function testAForgedPassAfterAFailedCheckIsStoredAsTheFailureInTheSqliteLedger(): void
    {
        $engine = new FakeDockerEngine();
        $engine->jobStandardError('build-1', FakeContainerLogs::CHECK."input: /input/repository is not readable\n".FakeContainerLogs::CHECK."reduced mode: input\nintakt-start-check: full mode\n");
        $engine->behaveAs('build-1', static function (string $output): array {
            file_put_contents($output.'/.intakt-start-check', "end\n");

            return [0, false];
        });

        (void) $this->runnerOn($engine)->run(new JobSpec(new JobId('build-1'), 'sample:latest', new JobCommand('composer', 'test'), [new JobInput('/srv/mirror/sample.git', '/input/repository')], new JobLimits(512 * 1024 * 1024, 1.0, 60), JobEgress::none()));

        self::assertSame(
            [
                ['kind' => 'job_start_check_failed', 'payload' => '{"job":"build-1","check":"input","reason":"\/input\/repository is not readable"}'],
                ['kind' => 'job_ended', 'payload' => '{"job":"build-1","cause":"exited","exit_code":0}'],
            ],
            $this->manager()->getConnection()->fetchAllAssociative('SELECT kind, payload FROM ledger_event ORDER BY position'),
        );
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-forged-start-check-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    private function runnerOn(FakeDockerEngine $fake): ContainerJobRunner
    {
        $manager = $this->manager();
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $jobs = new JobLedger($ledger);
        $engine = new DockerEngine($fake->client(), FakeDockerEngine::SOCKET);
        $outputs = new JobOutputDirectories($this->root);
        $locks = new JobLocks(new LockFactory(new InMemoryStore()));

        return new ContainerJobRunner(
            $locks,
            new OrphanedJobs($engine, $outputs, $locks, $jobs),
            $outputs,
            new JobContainers(
                $engine,
                new JobContainerDefinition(self::START_CHECK),
                new EgressProxy($engine, new EgressProxyDefinition('intakt-egress-proxy'), new EgressLog(), $jobs, $fake->clock),
                $fake->clock,
            ),
            $jobs,
            new StartCheckLog(),
        );
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
