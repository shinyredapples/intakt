<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\JudgeRound\PublishJudgedRound;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('persistence')]
#[CoversClass(LiveRoundProgress::class)]
#[CoversClass(RoundProgress::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(PublishJudgedRound::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(ReviewRound::class)]
final class LiveRoundProgressTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAWorkerThatFinishesWhileAnotherPublishesNeverShowsTheRoundGoingBackwards(): void
    {
        $round = $this->fixture->start(2);
        $this->fixture->updates->whilePublishingNextRoundChange = fn () => $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole([]));

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([$this->state($round, '1/2'), $this->state($round, '2/2')], $this->fixture->updates->seen);
    }

    public function testAWorkerThatFindsThePublicationTakenLeavesItsStateToTheWorkerHoldingIt(): void
    {
        $round = $this->fixture->start(2);
        $this->fixture->updates->whilePublishingNextRoundChange = fn () => $this->fixture->liveProgress($this->fixture->entities)->publishLatest($round->id());

        $this->fixture->liveProgress($this->fixture->entities)->publishLatest($round->id());

        self::assertSame([$this->state($round, '0/2')], $this->fixture->updates->seen);
    }

    public function testWorkersThatFinishOneAfterTheOtherEachPublishTheirState(): void
    {
        $round = $this->fixture->start(2);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole([]));

        self::assertSame([$this->state($round, '1/2'), $this->state($round, '2/2')], $this->fixture->updates->seen);
    }

    private function state(ReviewRound $round, string $finished): string
    {
        return sprintf('round %s %s', $round->id()->toRfc4122(), $finished);
    }
}
