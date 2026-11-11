<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\JobContainer;
use Intakt\Core\Job\Infrastructure\JobLedger;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Intakt\Core\Job\UseCase\SweepJobContainers\SweepJobContainersCommand;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(SweepJobContainersCommand::class)]
#[UsesClass(OrphanedJobs::class)]
#[UsesClass(DockerEngine::class)]
#[UsesClass(DockerEngineFailed::class)]
#[UsesClass(EngineAnswer::class)]
#[UsesClass(JobContainer::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobLedger::class)]
#[UsesClass(JobLocks::class)]
#[UsesClass(JobOutputDirectories::class)]
final class SweepJobContainersCommandTest extends TestCase
{
    private string $root = '';
    private FakeDockerEngine $engine;
    private InMemoryLedger $ledger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-jobs-'.bin2hex(random_bytes(4));
        $this->engine = new FakeDockerEngine();
        $this->ledger = new InMemoryLedger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheContainersOfAGoneOrchestratorAreRemovedAndListed(): void
    {
        $orphan = $this->engine->leaveRunning('build-7');

        $tester = $this->whenTheSweepRuns();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('removed %s (job build-7)', $orphan), $tester->getDisplay());
        self::assertStringContainsString('1 orphaned job containers removed.', $tester->getDisplay());
        self::assertSame([], $this->engine->containerIds());
        self::assertSame([LedgerEventKind::JobContainerRemoved], $this->ledger->kinds());
    }

    public function testAnUnreachableEngineFailsTheSweepWithItsReason(): void
    {
        $this->engine->goDown();

        $tester = $this->whenTheSweepRuns();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The Docker Engine is not reachable at', $tester->getDisplay());
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function testAMissingSocketFailsTheSweepWithItsPath(): void
    {
        $missing = sys_get_temp_dir().'/intakt-no-engine-'.bin2hex(random_bytes(4)).'.sock';

        $tester = $this->whenTheSweepRuns($missing);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('there is no socket at', $tester->getDisplay());
        self::assertSame([], $this->engine->sockets());
    }

    private function whenTheSweepRuns(string $socket = FakeDockerEngine::SOCKET): CommandTester
    {
        $engine = new DockerEngine($this->engine->client(), $socket);
        $locks = new JobLocks(new LockFactory(new InMemoryStore()));
        $application = new Application();
        $application->addCommand(new SweepJobContainersCommand(new OrphanedJobs($engine, new JobOutputDirectories($this->root), $locks, new JobLedger($this->ledger))));
        $tester = new CommandTester($application->find('intakt:job:sweep'));
        $tester->execute([]);

        return $tester;
    }
}
