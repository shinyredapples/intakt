<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\JudgeRound\PublishJudgedRound;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\PublishRoundStartHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[Group('persistence')]
#[CoversClass(LiveRoundProgress::class)]
#[CoversClass(RoundProgress::class)]
#[CoversClass(FinishedState::class)]
#[CoversClass(PublishRoundStartHandler::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(PublishJudgedRound::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewerRateLimited::class)]
#[UsesClass(ProviderRateLimited::class)]
final class LiveRoundProgressTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testAWorkerThatFinishesWhileAnotherPublishesNeverShowsTheRoundGoingBackwards(): void
    {
        $round = $this->fixture->start(2);
        $this->fixture->updates->whilePublishingNextRoundChange = fn () => $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole([]));

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([$this->state($round, '0/2'), $this->state($round, '1/2'), $this->state($round, '2/2')], $this->fixture->updates->seen);
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

        self::assertSame([$this->state($round, '0/2'), $this->state($round, '1/2'), $this->state($round, '1/2'), $this->state($round, '2/2')], $this->fixture->updates->seen);
    }

    public function testTheProgressNamesEachFinishedReviewerWithItsState(): void
    {
        $round = $this->fixture->start(2);
        $answered = $this->fixture->bus->messages[0];

        $this->fixture->deliver($answered, new VerdictPerRole([]));

        self::assertSame([$answered->reviewerId => FinishedState::Verdict], $this->fixture->updates->progress[1]->finished);
        self::assertSame([$this->state($round, '0/2'), $this->state($round, '1/2')], $this->fixture->updates->seen);
    }

    public function testAReviewerAWorkerTakesIsPublishedAsStarted(): void
    {
        $this->fixture->start(2);
        $taken = $this->fixture->bus->messages[0];

        $this->fixture->deliver($taken, new VerdictPerRole([]));

        self::assertSame([$taken->reviewerId], $this->fixture->updates->started);
    }

    public function testAReviewerThatAlreadyAnsweredIsNotPublishedAsStartedAgainWhenItsMessageComesBack(): void
    {
        $this->fixture->start(2);
        $taken = $this->fixture->bus->messages[0];
        $this->fixture->deliver($taken, new VerdictPerRole([]));

        $this->fixture->deliver($taken, new VerdictPerRole([]));

        self::assertSame([$taken->reviewerId], $this->fixture->updates->started);
    }

    public function testAReviewerThatStartsRaisesTheStartedCountAndLeavesTheFinishedCount(): void
    {
        $this->fixture->start(2);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([[1, 0], [1, 1]], array_map(static fn (RoundProgress $progress): array => [$progress->reviewersStarted, $progress->reviewersFinished], $this->fixture->updates->progress));
    }

    public function testAProgressThatDiffersOnlyInStartedReviewersIsNotTheSameProgress(): void
    {
        self::assertFalse(new RoundProgress(0, 2, false, [], 1)->isSameAs(new RoundProgress(0, 2, false, [], 0)));
    }

    public function testTheRoundsFirstProgressIsPublishedAtItsStartBeforeAnyReviewerRuns(): void
    {
        $round = $this->fixture->start(2);

        new PublishRoundStartHandler($this->fixture->liveProgress($this->fixture->entities))(new RoundOpened($round->id()->toRfc4122(), $round->project()));

        self::assertSame([$this->state($round, '0/2')], $this->fixture->updates->seen);
        self::assertSame(0, $this->fixture->updates->progress[0]->reviewersStarted);
    }

    public function testARateLimitedReviewerHandedBackIsPublishedAsNoLongerStarted(): void
    {
        $round = $this->fixture->start(1);

        try {
            $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(failure:new ProviderRateLimited(30, new RuntimeException('429'))));
            self::fail('The rate limit was recorded as a failure.');
        } catch (ReviewerRateLimited) {
        }

        self::assertSame(0, $this->fixture->round($round->id()->toRfc4122())->progress()->reviewersStarted);
        self::assertSame([[1, 0], [0, 0]], array_map(static fn (RoundProgress $progress): array => [$progress->reviewersStarted, $progress->reviewersFinished], $this->fixture->updates->progress));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function state(ReviewRound $round, string $finished): string
    {
        return sprintf('round %s %s', $round->id()->toRfc4122(), $finished);
    }
}
