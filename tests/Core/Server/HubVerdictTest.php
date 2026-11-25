<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\InfrastructureCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HubVerdict::class)]
#[UsesClass(InfrastructureCheck::class)]
#[UsesClass(EndpointDown::class)]
final class HubVerdictTest extends TestCase
{
    public function testAHealthyHubPasses(): void
    {
        $verdict = HubVerdict::of(HubHealth::Healthy);

        self::assertTrue($verdict->passes());
        self::assertNull($verdict->problem);
        self::assertNull($verdict->fix);
    }

    public function testAHubThatReportsUnhealthyFailsWithItsProblemAndFix(): void
    {
        $verdict = HubVerdict::of(HubHealth::Unhealthy);

        self::assertFalse($verdict->passes());
        self::assertSame('the hub reports unhealthy', $verdict->problem);
        self::assertSame("check the FrankenPHP log for the Mercure hub's error, for example a transport file under MERCURE_BOLT_PATH it cannot write", $verdict->fix);
    }

    public function testAHubEndpointThatIsDownFailsWithItsReasonAndFix(): void
    {
        $verdict = HubVerdict::of(new EndpointDown('/mercure/health/ready did not answer'));

        self::assertFalse($verdict->passes());
        self::assertSame('hub down (/mercure/health/ready did not answer)', $verdict->problem);
        self::assertSame("check that FrankenPHP runs with the Caddyfile's mercure block and that FRANKENPHP_ADMIN_URL points at its admin API", $verdict->fix);
    }
}
