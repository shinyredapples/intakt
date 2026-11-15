<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\Contract\UnknownRound;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversClass(ReplayMarks::class)]
#[CoversClass(LedgerRounds::class)]
#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(ReplayMarkOfRound::class)]
#[CoversClass(UnknownRound::class)]
final class StoredRoundReplayMarksTest extends KernelTestCase
{
    // Regression: the page read the project's current mark, so flagging the project relabeled a round that was opened live and whose verdicts still count.
    public function testAReviewOpenedBeforeItsProjectWasFlaggedStaysLiveOnTheReviewPage(): void
    {
        $container = self::getContainer();
        $round = new RoundFixture($container)->start(2)->id()->toRfc4122();
        $container->get('command.bus')->dispatch(new FlagProjectAsReplay('alpha'));

        self::assertSame(ReplayMark::Live, $container->get(LedgerReviewPages::class)->reviewOf($round)?->mark);
    }

    public function testAReviewOpenedAfterItsProjectWasFlaggedIsAReplayOnTheReviewPage(): void
    {
        $container = self::getContainer();
        $fixture = new RoundFixture($container);
        $container->get('command.bus')->dispatch(new FlagProjectAsReplay('alpha'));
        $round = $fixture->start(2)->id()->toRfc4122();

        self::assertSame(ReplayMark::Replay, $container->get(LedgerReviewPages::class)->reviewOf($round)?->mark);
    }
}
