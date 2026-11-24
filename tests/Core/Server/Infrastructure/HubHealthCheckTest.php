<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\HubHealthCheck;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use Intakt\Tests\Core\Server\RecordedAdminApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HubHealthCheck::class)]
#[UsesClass(CheckResult::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(AdminApiServerStatus::class)]
#[UsesClass(PrometheusSamples::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
#[UsesClass(AdminApiNotOnLocalhost::class)]
#[UsesClass(HubVerdict::class)]
final class HubHealthCheckTest extends TestCase
{
    public function testTheHubCheckPassesOnAHealthyHubReading(): void
    {
        $result = new HubHealthCheck(new RecordedAdminApi()->status())->run();

        self::assertTrue($result->passed);
    }

    public function testTheHubCheckFailsWithItsFixTextWhenTheHubIsUnhealthy(): void
    {
        $result = new HubHealthCheck(new RecordedAdminApi()->answering('/mercure/health/ready', '', 503)->status())->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('the hub reports unhealthy. Restart the container', $result->fix);
    }

    public function testTheHubCheckFailsWhenItsEndpointIsDown(): void
    {
        $result = new HubHealthCheck(new RecordedAdminApi()->unreachable()->status())->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('hub down (', $result->fix);
    }
}
