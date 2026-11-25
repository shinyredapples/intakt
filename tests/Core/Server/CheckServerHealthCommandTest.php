<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use Intakt\Core\Server\UseCase\CheckServerHealth\CheckServerHealthCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckServerHealthCommand::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(AdminApiServerStatus::class)]
#[UsesClass(PrometheusSamples::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
#[UsesClass(AdminApiNotOnLocalhost::class)]
#[UsesClass(InfrastructureChecks::class)]
#[UsesClass(HubVerdict::class)]
final class CheckServerHealthCommandTest extends TestCase
{
    public function testARecordedHealthyServerPassesTheHealthCheck(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->status());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("healthy\n", $tester->getDisplay());
    }

    public function testAHubThatReportsUnhealthyFailsTheHealthCheck(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/mercure/health/ready', '', 503)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("unhealthy: the hub reports unhealthy\n", $tester->getDisplay());
    }

    public function testAServerWhoseWorkerThreadsAreAllRestartingFailsTheHealthCheck(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/frankenphp/threads', RecordedAdminApi::fixture('threads_no_ready_worker.json'))->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("unhealthy: no worker thread is ready\n", $tester->getDisplay());
    }

    public function testAServerWhoseWorkerThreadsAreAllBusyStillPassesTheHealthCheck(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/frankenphp/threads', RecordedAdminApi::fixture('threads_all_busy.json'))->status());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testAnAdminApiThatIsDownFailsTheHealthCheckWithEachReason(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->unreachable()->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            "unhealthy: worker threads down (/frankenphp/threads did not answer)\n"
            ."unhealthy: server metrics down (/metrics did not answer)\n"
            ."unhealthy: hub down (/mercure/health/ready did not answer)\n",
            $tester->getDisplay(),
        );
    }

    public function testAMetricsEndpointThatAnswersAnErrorFailsTheHealthCheck(): void
    {
        $tester = $this->execute(new RecordedAdminApi()->answering('/metrics', '', 500)->status());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("unhealthy: server metrics down (/metrics answered with status 500)\n", $tester->getDisplay());
    }

    public function testAnAdminApiOffLocalhostFailsTheHealthCheckWithoutARequest(): void
    {
        $api = new RecordedAdminApi();

        $tester = $this->execute($api->status('http://192.0.2.10:2019'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('unhealthy: FRANKENPHP_ADMIN_URL must be plain http on localhost', $tester->getDisplay());
        self::assertSame([], $api->requestedUrls);
    }

    private function execute(ServerStatus $status): CommandTester
    {
        $application = new Application();
        $application->addCommand(new CheckServerHealthCommand($status));
        $tester = new CommandTester($application->find('intakt:server:health'));
        $tester->execute([]);

        return $tester;
    }
}
