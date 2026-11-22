<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;

// On every engine, because the order a stored round lists its reviewers in comes from the database's ORDER BY on their ids.
#[Group('persistence')]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(RoundReviewer::class)]
#[UsesClass(StoredFindings::class)]
#[UsesClass(ChangeFindings::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(ChangeAddress::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(RunReviewRound::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
final class ReviewerIdOrderTest extends KernelTestCase
{
    public function testAPanelOpenedWhileTheClockGoesBackReadsBackInPanelOrder(): void
    {
        $fixture = new RoundFixture(self::getContainer());

        $rounds = $fixture->transactionsAt($this->clockGoingBack());

        $round = $rounds->inOneTransaction(static fn (): ReviewRound => $rounds->open($fixture->commandFor(RoundFixture::CHANGE, 2)));

        self::assertSame(['role-1', 'role-2'], array_map(static fn (RoundReviewer $reviewer): string => $reviewer->roleName(), $fixture->round($round->id()->toRfc4122())->reviewers()));
    }

    // A millisecond earlier on every call, as a clock that an NTP step sets back hands out times.
    private function clockGoingBack(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-11-22 08:30:00.005');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(static function () use (&$now): DateTimeImmutable {
            $now = $now->modify('-1 millisecond');

            return $now;
        });

        return $clock;
    }
}
