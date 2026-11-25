<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfrastructureChecks::class)]
#[UsesClass(HubVerdict::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
final class InfrastructureChecksTest extends TestCase
{
    public function testEveryCheckPassesOnAHealthyInstall(): void
    {
        $checks = InfrastructureChecks::of($this->reading());

        self::assertSame([], $checks->problems);
    }

    public function testTheWorkerThreadsCheckFailsWithItsReasonWhenTheThreadsEndpointIsDown(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new EndpointDown('/frankenphp/threads did not answer')));

        self::assertSame(['worker threads down (/frankenphp/threads did not answer)'], $checks->problems);
    }

    public function testTheWorkerThreadsCheckFailsWhenNoThreadIsBusyOrIdle(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new WorkerThreads(0, 0)));

        self::assertSame(['no worker thread is ready'], $checks->problems);
    }

    public function testABusyThreadCountsAsReadySoTheWorkerThreadsCheckPassesWithNoIdleThread(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new WorkerThreads(1, 0)));

        self::assertSame([], $checks->problems);
    }

    public function testTheServerMetricsCheckFailsWithItsReasonWhenTheMetricsEndpointIsDown(): void
    {
        $checks = InfrastructureChecks::of($this->reading(metrics: new EndpointDown('/metrics answered with status 500')));

        self::assertSame(['server metrics down (/metrics answered with status 500)'], $checks->problems);
    }

    public function testTheHubCheckFailsWithItsReasonWhenTheHubEndpointIsDown(): void
    {
        $checks = InfrastructureChecks::of($this->reading(hub: new EndpointDown('/mercure/health/ready did not answer')));

        self::assertSame(['hub down (/mercure/health/ready did not answer)'], $checks->problems);
    }

    public function testTheHubCheckFailsWhenTheHubReportsUnhealthy(): void
    {
        $checks = InfrastructureChecks::of($this->reading(hub: HubHealth::Unhealthy));

        self::assertSame(['the hub reports unhealthy'], $checks->problems);
    }

    public function testEveryFailingCheckReportsItsProblemInTheOrderThreadsMetricsHub(): void
    {
        $down = new EndpointDown('refused');

        $checks = InfrastructureChecks::of(new ServerReading($down, $down, $down));

        self::assertSame(['worker threads down (refused)', 'server metrics down (refused)', 'hub down (refused)'], $checks->problems);
    }

    private function reading(
        WorkerThreads|EndpointDown $threads = new WorkerThreads(1, 3),
        ServerMetrics|EndpointDown $metrics = new ServerMetrics(0, 0),
        HubHealth|EndpointDown $hub = HubHealth::Healthy,
    ): ServerReading {
        return new ServerReading($threads, $metrics, $hub);
    }
}
