<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkReplay;

use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\MarkReplay\ReplayMarkOfProjectHandler;
use Intakt\Core\Review\UseCase\MarkReplay\ReplayMarkOfRoundHandler;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(FlagProjectAsReplayHandler::class)]
#[CoversClass(ReplayMarkOfProjectHandler::class)]
#[CoversClass(ReplayMarkOfRoundHandler::class)]
#[CoversClass(FlagProjectAsReplay::class)]
#[CoversClass(ReplayMarkOfProject::class)]
#[CoversClass(ReplayMarkOfRound::class)]
#[CoversClass(ReplayMark::class)]
#[CoversClass(ReplayProject::class)]
#[CoversClass(ReviewRound::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(RoundNotFound::class)]
final class ReplayMarksTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testAProjectNeverFlaggedReadsAsLive(): void
    {
        self::assertSame(ReplayMark::Live, $this->markOfProject('alpha'));
    }

    public function testAProjectFlaggedAsReplayReadsAsReplay(): void
    {
        $this->flag('alpha');

        self::assertSame(ReplayMark::Replay, $this->markOfProject('alpha'));
    }

    public function testFlaggingOneProjectLeavesAnotherProjectLive(): void
    {
        $this->flag('alpha');

        self::assertSame(ReplayMark::Live, $this->markOfProject('beta'));
    }

    public function testFlaggingAProjectTwiceKeepsItAReplay(): void
    {
        $this->flag('alpha');
        $this->flag('alpha');

        self::assertSame(ReplayMark::Replay, $this->markOfProject('alpha'));
    }

    public function testAReviewOfAFlaggedProjectIsStoredAsReplay(): void
    {
        $this->flag('alpha');

        $round = $this->fixture->start(2)->id()->toRfc4122();

        self::assertSame(ReplayMark::Replay, $this->markOfRound($round));
    }

    public function testAReviewOfAProjectNeverFlaggedIsStoredAsLive(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();

        self::assertSame(ReplayMark::Live, $this->markOfRound($round));
    }

    // The mark is the one the round was opened with, because a replay that turned live afterwards would count verdicts the reviewers never gave.
    public function testAReviewOpenedBeforeItsProjectWasFlaggedStaysLive(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();

        $this->flag('alpha');

        self::assertSame(ReplayMark::Live, $this->markOfRound($round));
    }

    public function testTheReplayMarkOfAnUnknownReviewIsRefused(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->markOfRound('not-a-round');
    }

    public function testTheReplayMarkOfAReviewNeverStoredIsRefused(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->markOfRound(Uuid::v7()->toRfc4122());
    }

    // A reviewer's handler asks for the mark through the board while it still holds its round, so the answer must not detach that round.
    public function testReadingTheReplayMarkOfAReviewLeavesTheRoundTheCallerHoldsManaged(): void
    {
        $round = $this->fixture->start(2);

        $this->markOfRound($round->id()->toRfc4122());

        self::assertTrue($this->fixture->entities->contains($round));
    }

    public function testAnotherModuleFlagsAProjectAndReadsItsMarkThroughTheBuses(): void
    {
        $container = self::getContainer();
        $commands = $container->get('command.bus');
        $queries = $container->get('query.bus');

        $commands->dispatch(new FlagProjectAsReplay('alpha'));
        $mark = $queries->dispatch(new ReplayMarkOfProject('alpha'))->last(HandledStamp::class)?->getResult();

        self::assertSame(ReplayMark::Replay, $mark);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function flag(string $project): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay($project));
    }

    private function markOfProject(string $project): ReplayMark
    {
        $this->fixture->entities->clear();

        return new ReplayMarkOfProjectHandler($this->fixture->entities)(new ReplayMarkOfProject($project));
    }

    private function markOfRound(string $round): ReplayMark
    {
        return new ReplayMarkOfRoundHandler($this->fixture->transactions())(new ReplayMarkOfRound($round));
    }
}
