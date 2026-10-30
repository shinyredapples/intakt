<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelCannotReachQuorum;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\PanelWithoutReviewers;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Panel::class)]
#[CoversClass(PanelCannotReachQuorum::class)]
#[UsesClass(PanelWithoutReviewers::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
final class PanelTest extends TestCase
{
    public function testAPanelWithoutReviewersCannotBeBuilt(): void
    {
        $this->expectException(PanelWithoutReviewers::class);

        new Panel([], QuorumPolicy::All);
    }

    public function testAOneStrongPlusOnePanelOfASingleReviewerCannotBeBuilt(): void
    {
        $this->expectExceptionObject(new PanelCannotReachQuorum(QuorumPolicy::OneStrongPlusOne));

        new Panel([$this->reviewer(Tier::Strong)], QuorumPolicy::OneStrongPlusOne);
    }

    public function testAOneStrongPlusOnePanelWithoutAStrongReviewerCannotBeBuilt(): void
    {
        $this->expectException(PanelCannotReachQuorum::class);

        new Panel([$this->reviewer(Tier::Standard), $this->reviewer(Tier::Light)], QuorumPolicy::OneStrongPlusOne);
    }

    public function testAOneStrongPlusOnePanelWithAStrongReviewerAndAnotherIsBuilt(): void
    {
        $panel = new Panel([$this->reviewer(Tier::Strong), $this->reviewer(Tier::Light)], QuorumPolicy::OneStrongPlusOne);

        self::assertCount(2, $panel->reviewers);
    }

    private function reviewer(Tier $tier): PanelReviewer
    {
        return new PanelReviewer(new ReviewerRole('correctness', 'Check the logic.'), new ReviewerModel($tier, Provider::Anthropic), 3);
    }
}
