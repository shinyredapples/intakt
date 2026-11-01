<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\HoldRoundUntilEveryReviewerFinished;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use Intakt\Tests\Core\Review\SampleIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Workflow\WorkflowInterface;

// Through the container's state machines, because the guard only holds a round when the workflow dispatches its guard event.
#[CoversClass(HoldRoundUntilEveryReviewerFinished::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(RoundReviewer::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(StateMarkingStore::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelReviewer::class)]
final class HoldRoundUntilEveryReviewerFinishedTest extends KernelTestCase
{
    public function testARoundCannotCompleteWhileAReviewerHasNotFinished(): void
    {
        $round = $this->round(2);
        $this->finish($round->reviewers()[0]);
        $this->reviewerMachine()->apply($round->reviewers()[1], ReviewerTransition::Start->value);

        self::assertFalse($this->roundMachine()->can($round, ReviewRound::COMPLETION));
    }

    public function testARoundCompletesOnceAndOnlyOnceWhenEveryReviewerFinished(): void
    {
        $round = $this->round(2);
        array_map($this->finish(...), $round->reviewers());

        self::assertTrue($this->roundMachine()->can($round, ReviewRound::COMPLETION));
        $this->roundMachine()->apply($round, ReviewRound::COMPLETION);

        self::assertSame(RoundState::Complete, $round->state());
        self::assertFalse($this->roundMachine()->can($round, ReviewRound::COMPLETION));
    }

    private function finish(RoundReviewer $reviewer): void
    {
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Start->value);
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Receive->value);
    }

    private function round(int $reviewers): ReviewRound
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.'), SampleIdentity::any()), new ChangeRound('feat/thing', 1, '[]'));

        foreach (range(1, $reviewers) as $number) {
            $round->addReviewer(new UuidV7(), new PanelReviewer(new ReviewerRole('role-'.$number, 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1));
        }

        return $round;
    }

    private function reviewerMachine(): WorkflowInterface
    {
        $machine = self::getContainer()->get('state_machine.reviewer');
        assert($machine instanceof WorkflowInterface);

        return $machine;
    }

    private function roundMachine(): WorkflowInterface
    {
        $machine = self::getContainer()->get('state_machine.review_round');
        assert($machine instanceof WorkflowInterface);

        return $machine;
    }
}
