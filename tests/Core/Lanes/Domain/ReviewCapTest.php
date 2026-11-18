<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Lanes\Domain;

use Intakt\Core\Lanes\Contract\HeadWait;
use Intakt\Core\Lanes\Domain\ReviewCap;
use Intakt\Core\Lanes\Domain\ReviewCapMustBePositive;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewCap::class)]
#[CoversClass(ReviewCapMustBePositive::class)]
#[UsesClass(HeadWait::class)]
final class ReviewCapTest extends TestCase
{
    /**
     * @return iterable<string, array{HeadWait}>
     */
    public static function headsNoReviewerIsDueFor(): iterable
    {
        yield 'waiting for CI' => [HeadWait::ForCi];
        yield 'waiting for a runner' => [HeadWait::ForRunner];
        yield 'waiting for landing' => [HeadWait::ForLanding];
    }

    /**
     * @return iterable<string, array{HeadWait}>
     */
    public static function headsWaitingForAReviewer(): iterable
    {
        yield 'queued for a reviewer' => [HeadWait::ForReviewer];
        yield 'in review' => [HeadWait::InReview];
    }

    #[DataProvider('headsNoReviewerIsDueFor')]
    public function testAHeadNoReviewerIsDueForDoesNotHoldABuilderAtTheReviewCap(HeadWait $wait): void
    {
        $cap = new ReviewCap(1);

        self::assertTrue($cap->admitsABuildBeside([$wait]));
    }

    #[DataProvider('headsWaitingForAReviewer')]
    public function testAHeadWaitingForAReviewerHoldsABuilderAtTheReviewCap(HeadWait $wait): void
    {
        $cap = new ReviewCap(1);

        self::assertFalse($cap->admitsABuildBeside([$wait]));
    }

    public function testABuilderMayStartWhileFewerHeadsThanTheCapWaitForAReviewer(): void
    {
        $cap = new ReviewCap(2);

        self::assertTrue($cap->admitsABuildBeside([
            HeadWait::InReview,
            HeadWait::ForCi,
            HeadWait::ForCi,
            HeadWait::ForRunner,
            HeadWait::ForLanding,
        ]));
    }

    public function testHeadsWaitingForAReviewerUpToTheCapHoldABuilder(): void
    {
        $cap = new ReviewCap(2);

        self::assertFalse($cap->admitsABuildBeside([HeadWait::ForReviewer, HeadWait::InReview, HeadWait::ForCi]));
    }

    public function testABuilderMayStartWhenNoHeadIsInFlight(): void
    {
        $cap = new ReviewCap(1);

        self::assertTrue($cap->admitsABuildBeside([]));
    }

    public function testACapOfOneHeadIsAccepted(): void
    {
        $cap = new ReviewCap(1);

        self::assertSame(1, $cap->heads);
    }

    public function testACapOfNoHeadIsRefused(): void
    {
        try {
            new ReviewCap(0);
        } catch (ReviewCapMustBePositive $refused) {
            self::assertSame('The review cap must allow at least one head, 0 given.', $refused->getMessage());

            return;
        }

        self::fail('A cap of no head was accepted.');
    }
}
