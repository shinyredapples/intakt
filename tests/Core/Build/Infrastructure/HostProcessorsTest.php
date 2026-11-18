<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Infrastructure\HostProcessors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostProcessors::class)]
#[UsesClass(GatesAtOnce::class)]
final class HostProcessorsTest extends TestCase
{
    public function testMoreBuildSlotsThanAnyHostHasProcessorsStillRunTheFloorOfGates(): void
    {
        self::assertSame(GatesAtOnce::FEWEST, new HostProcessors()->gatesAtOnce(100_000)->count);
    }

    public function testOneBuildSlotRunsTheHostsOnlineProcessorsUpToTheCeiling(): void
    {
        $online = posix_sysconf(POSIX_SC_NPROCESSORS_ONLN);

        self::assertSame(min(GatesAtOnce::MOST, max(GatesAtOnce::FEWEST, $online)), new HostProcessors()->gatesAtOnce(1)->count);
    }
}
