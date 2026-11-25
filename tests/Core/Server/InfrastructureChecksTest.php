<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\InfrastructureCheck;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfrastructureChecks::class)]
#[UsesClass(InfrastructureCheck::class)]
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
        self::assertTrue($checks->threads->passes());
        self::assertTrue($checks->metrics->passes());
        self::assertTrue($checks->hub->passes());
    }

    public function testTheWorkerThreadsCheckFailsWithItsReasonAndFixWhenTheThreadsEndpointIsDown(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new EndpointDown('/frankenphp/threads did not answer')));

        self::assertSame(['worker threads down (/frankenphp/threads did not answer)'], $checks->problems);
        self::assertSame('check that FrankenPHP is running and that FRANKENPHP_ADMIN_URL points at its admin API', $checks->threads->fix);
    }

    public function testTheWorkerThreadsCheckFailsWithItsFixWhenNoThreadIsBusyOrIdle(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new WorkerThreads(0, 0)));

        self::assertSame(['no worker thread is ready'], $checks->problems);
        self::assertSame('check the FrankenPHP log for a worker that fails to boot or keeps restarting', $checks->threads->fix);
    }

    public function testABusyThreadCountsAsReadySoTheWorkerThreadsCheckPassesWithNoIdleThread(): void
    {
        $checks = InfrastructureChecks::of($this->reading(threads: new WorkerThreads(1, 0)));

        self::assertTrue($checks->threads->passes());
        self::assertNull($checks->threads->fix);
    }

    public function testTheServerMetricsCheckFailsWithItsReasonAndFixWhenTheMetricsEndpointIsDown(): void
    {
        $checks = InfrastructureChecks::of($this->reading(metrics: new EndpointDown('/metrics answered in an unusable shape')));

        self::assertSame(['server metrics down (/metrics answered in an unusable shape)'], $checks->problems);
        self::assertSame("check that the Caddyfile's global options turn on metrics and that FRANKENPHP_ADMIN_URL points at the admin API", $checks->metrics->fix);
    }

    public function testTheHubCheckIsTheHubVerdict(): void
    {
        $checks = InfrastructureChecks::of($this->reading(hub: HubHealth::Unhealthy));

        self::assertEquals(HubVerdict::of(HubHealth::Unhealthy), $checks->hub);
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
