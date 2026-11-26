<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplayIsRefused::class)]
final class ReplayIsRefusedTest extends TestCase
{
    public function testABusyReplayLockMeansTheLockIsBusy(): void
    {
        self::assertTrue(ReplayIsRefused::whileAnotherReplayRuns()->meansTheReplayLockIsBusy());
    }

    public function testABusyReplayLockDoesNotClaimThatAnotherPlayCameFirst(): void
    {
        self::assertFalse(ReplayIsRefused::whileAnotherReplayRuns()->meansAnotherPlayCameFirst());
    }

    public function testARoundTheLedgerHoldsDoesNotMeanTheLockIsBusy(): void
    {
        self::assertFalse(ReplayIsRefused::becauseTheLedgerHolds('round-1')->meansTheReplayLockIsBusy());
    }

    public function testARoundTheLedgerHoldsMeansAnotherPlayCameFirst(): void
    {
        self::assertTrue(ReplayIsRefused::becauseTheLedgerHolds('round-1')->meansAnotherPlayCameFirst());
    }

    public function testAModelCallAnEarlierPlayHoldsMeansAnotherPlayCameFirst(): void
    {
        self::assertTrue(ReplayIsRefused::becauseTheLedgerHoldsCall('call-1')->meansAnotherPlayCameFirst());
    }

    public function testAModelCallAnotherReviewHoldsDoesNotClaimThatAnotherPlayCameFirst(): void
    {
        self::assertFalse(ReplayIsRefused::becauseAnotherReviewHoldsCall('call-1')->meansAnotherPlayCameFirst());
    }

    public function testAModelCallAnotherReviewHoldsDoesNotMeanTheLockIsBusy(): void
    {
        self::assertFalse(ReplayIsRefused::becauseAnotherReviewHoldsCall('call-1')->meansTheReplayLockIsBusy());
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
