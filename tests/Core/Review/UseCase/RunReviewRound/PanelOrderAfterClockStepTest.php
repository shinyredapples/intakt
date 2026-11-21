<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(ReviewRound::class)]
#[CoversClass(RoundReviewer::class)]
final class PanelOrderAfterClockStepTest extends KernelTestCase
{
    public function testAStoredRoundReloadsItsReviewersInPanelOrderWhenTheClockStepsBackBetweenThem(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $command = $fixture->commandFor('clock-step', 2);
        $id = Uuid::v7();
        $round = new ReviewRound($id, $command->target, new ChangeRound('clock-step', 1, null));

        foreach ($command->panel->reviewers as $reviewer) {
            $round->addReviewer(new UuidV7(UuidV7::generate($fixture->clock->now())), $reviewer);
            $fixture->clock->modify('-1 millisecond');
        }

        $fixture->entities->persist($round);
        $fixture->entities->flush();
        $fixture->entities->clear();

        self::assertSame(
            ['role-1', 'role-2'],
            array_map(static fn (RoundReviewer $reviewer): string => $reviewer->roleName(), $fixture->round($id->toRfc4122())->reviewers()),
        );
    }

    public function testReviewersStoredBeforeThePanelPositionKeepTheOrderOfTheirIds(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $command = $fixture->commandFor('before-position', 1);
        $round = new ReviewRound(Uuid::v7(), $command->target, new ChangeRound('before-position', 1, null));
        $member = $command->panel->reviewers[0];
        $earlier = new RoundReviewer(new UuidV7('01a18cae-0000-7000-8000-000000000001'), $round, $member, 0);
        $later = new RoundReviewer(new UuidV7('01a18cae-0000-7000-8000-000000000002'), $round, $member, 0);

        self::assertSame([-1, 1], [$earlier->panelOrder($later), $later->panelOrder($earlier)]);
    }
}
