<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Lanes\Domain;

use Intakt\Core\Lanes\Contract\HeadWait;
use Intakt\Core\Lanes\Domain\HeadsInFlightLimit;
use Intakt\Core\Lanes\Domain\HeadsInFlightLimitMustBePositive;
use Intakt\Core\Lanes\Domain\ReviewCap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeadsInFlightLimit::class)]
#[CoversClass(HeadsInFlightLimitMustBePositive::class)]
#[UsesClass(HeadWait::class)]
#[UsesClass(ReviewCap::class)]
final class HeadsInFlightLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{HeadWait}>
     */
    public static function everyWaitOfAHeadInFlight(): iterable
    {
        foreach (HeadWait::cases() as $wait) {
            yield $wait->value => [$wait];
        }
    }

    public function testTheLimitHoldsABuilderWhenHeadsWaitingForCiOrLandingFillItBelowTheReviewCap(): void
    {
        $cap = new ReviewCap(2);
        $limit = HeadsInFlightLimit::twiceThe($cap);
        $headsInFlight = [HeadWait::ForCi, HeadWait::ForCi, HeadWait::ForLanding, HeadWait::InReview];

        self::assertTrue($cap->admitsABuildBeside($headsInFlight));
        self::assertFalse($limit->admitsABuildBeside($headsInFlight));
    }

    #[DataProvider('everyWaitOfAHeadInFlight')]
    public function testEveryHeadInFlightCountsAgainstTheLimitWhateverItWaitsFor(HeadWait $wait): void
    {
        $limit = new HeadsInFlightLimit(1);

        self::assertFalse($limit->admitsABuildBeside([$wait]));
    }

    public function testABuilderMayStartWhileFewerHeadsThanTheLimitAreInFlight(): void
    {
        $limit = new HeadsInFlightLimit(3);

        self::assertTrue($limit->admitsABuildBeside([HeadWait::ForCi, HeadWait::ForLanding]));
    }

    public function testABuilderMayStartWhenNoHeadIsInFlight(): void
    {
        $limit = new HeadsInFlightLimit(1);

        self::assertTrue($limit->admitsABuildBeside([]));
    }

    public function testTheDefaultLimitIsTwiceTheReviewCap(): void
    {
        $limit = HeadsInFlightLimit::twiceThe(new ReviewCap(3));

        self::assertSame(6, $limit->heads);
    }

    public function testALimitOfOneHeadIsAccepted(): void
    {
        $limit = new HeadsInFlightLimit(1);

        self::assertSame(1, $limit->heads);
    }

    public function testALimitOfNoHeadIsRefused(): void
    {
        try {
            new HeadsInFlightLimit(0);
        } catch (HeadsInFlightLimitMustBePositive $refused) {
            self::assertSame('The limit on heads in flight must allow at least one head, 0 given.', $refused->getMessage());

            return;
        }

        self::fail('A limit of no head was accepted.');
    }
}
