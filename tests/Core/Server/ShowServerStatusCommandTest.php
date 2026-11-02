<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use Intakt\Core\Server\UseCase\ShowServerStatus\ShowServerStatusCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowServerStatusCommand::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(AdminApiServerStatus::class)]
#[UsesClass(PrometheusSamples::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
#[UsesClass(AdminApiNotOnLocalhost::class)]
final class ShowServerStatusCommandTest extends TestCase
{
    public function testARecordedServerIsPrintedAndExitsZero(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("worker threads: 0 busy, 2 idle\nqueue depth: 0\nworker restarts: 2\nhub: healthy\n", $tester->getDisplay());
    }

    public function testADownEndpointIsPrintedWithItsReasonAndExitsNonZero(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/metrics', '', 500)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('queue depth: down (/metrics answered with status 500)', $tester->getDisplay());
        self::assertStringContainsString('worker restarts: down (/metrics answered with status 500)', $tester->getDisplay());
        self::assertStringContainsString('hub: healthy', $tester->getDisplay());
    }

    public function testAnAdminApiOffLocalhostIsReportedAndExitsNonZero(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status('http://192.0.2.10:2019'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must be plain http on localhost', $tester->getDisplay());
    }

    private function execute(ServerStatus $status): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ShowServerStatusCommand($status));
        $tester = new CommandTester($application->find('intakt:server:status'));
        $tester->execute([]);

        return $tester;
    }
}
