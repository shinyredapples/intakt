<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpperWait::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class UpperWaitTest extends TestCase
{
    public function testTheUpperWaitIsAnHourForEachReviewerPlusAnHourForTheJudge(): void
    {
        self::assertSame(3 * 3600, new UpperWait()->secondsFor($this->panelOf(2)));
    }

    public function testEachReviewerAddsItsOwnTimeLimit(): void
    {
        self::assertSame(3 * RoundReviewer::REVIEW_CEILING_SECONDS + UpperWait::JUDGE_SECONDS, new UpperWait()->secondsFor($this->panelOf(3)));
    }

    public function testTheUpperWaitOfAStoredRoundIsWorkedOutFromItsReviewerCount(): void
    {
        self::assertSame(2 * RoundReviewer::REVIEW_CEILING_SECONDS + UpperWait::JUDGE_SECONDS, new UpperWait()->secondsForReviewers(2));
    }

    private function panelOf(int $reviewers): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            range(1, $reviewers),
        );

        return new Panel($members, QuorumPolicy::All);
    }
}
