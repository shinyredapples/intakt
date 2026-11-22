<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplayIsRefused::class)]
final class ReplayIsRefusedTest extends TestCase
{
    public function testARunningReplayMeansAnotherPlayCameFirst(): void
    {
        self::assertTrue(ReplayIsRefused::whileAnotherReplayRuns()->meansAnotherPlayCameFirst());
    }

    public function testARoundTheLedgerHoldsMeansAnotherPlayCameFirst(): void
    {
        self::assertTrue(ReplayIsRefused::becauseTheLedgerHolds('round-1')->meansAnotherPlayCameFirst());
    }

    public function testAModelCallTheLedgerHoldsMeansAnotherPlayCameFirst(): void
    {
        self::assertTrue(ReplayIsRefused::becauseTheLedgerHoldsCall('call-1')->meansAnotherPlayCameFirst());
    }

    public function testAnUpdateWithoutATopicIsARefusalOfItsOwn(): void
    {
        self::assertFalse(ReplayIsRefused::becauseAnUpdateHasNoTopic(0)->meansAnotherPlayCameFirst());
    }

    public function testADemoProjectThatIsNoReplayIsARefusalOfItsOwn(): void
    {
        self::assertFalse(ReplayIsRefused::becauseTheDemoProjectIsNoReplay('demo-replay')->meansAnotherPlayCameFirst());
    }
}
