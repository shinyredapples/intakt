<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubState;
use Intakt\Core\Server\Contract\HubVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HubVerdict::class)]
#[UsesClass(EndpointDown::class)]
final class HubVerdictTest extends TestCase
{
    public function testAHealthyHubIsHealthyWithNoProblem(): void
    {
        $verdict = HubVerdict::of(HubHealth::Healthy);

        self::assertSame(HubState::Healthy, $verdict->state);
        self::assertSame('healthy', $verdict->detail);
        self::assertNull($verdict->problem());
    }

    public function testAnUnhealthyHubIsUnhealthyAndSaysSo(): void
    {
        $verdict = HubVerdict::of(HubHealth::Unhealthy);

        self::assertSame(HubState::Unhealthy, $verdict->state);
        self::assertSame('the hub reports unhealthy', $verdict->problem());
    }

    public function testAHubWhoseEndpointIsDownIsDownWithItsReason(): void
    {
        $verdict = HubVerdict::of(new EndpointDown('connection refused'));

        self::assertSame(HubState::Down, $verdict->state);
        self::assertSame('connection refused', $verdict->detail);
        self::assertSame('hub down (connection refused)', $verdict->problem());
    }
}
