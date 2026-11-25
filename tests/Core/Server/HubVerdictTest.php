<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HubVerdict::class)]
#[UsesClass(EndpointDown::class)]
final class HubVerdictTest extends TestCase
{
    public function testAHealthyHubPasses(): void
    {
        $verdict = HubVerdict::of(HubHealth::Healthy);

        self::assertTrue($verdict->passes());
        self::assertNull($verdict->problem);
    }

    public function testAHubThatReportsUnhealthyFailsWithItsProblem(): void
    {
        $verdict = HubVerdict::of(HubHealth::Unhealthy);

        self::assertFalse($verdict->passes());
        self::assertSame('the hub reports unhealthy', $verdict->problem);
    }

    public function testAHubEndpointThatIsDownFailsWithItsReason(): void
    {
        $verdict = HubVerdict::of(new EndpointDown('/mercure/health/ready did not answer'));

        self::assertFalse($verdict->passes());
        self::assertSame('hub down (/mercure/health/ready did not answer)', $verdict->problem);
    }
}
