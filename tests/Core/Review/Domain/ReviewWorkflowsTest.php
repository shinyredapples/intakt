<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use InvalidArgumentException;
use stdClass;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Domain\RoundTransition;
use Intakt\Core\Review\Infrastructure\HoldRoundUntilEveryReviewerFinished;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\WorkflowInterface;

#[CoversClass(ReviewRound::class)]
#[CoversClass(RoundReviewer::class)]
#[CoversClass(ReviewerState::class)]
#[CoversClass(ReviewerTransition::class)]
#[CoversClass(RoundTransition::class)]
#[CoversClass(StateMarkingStore::class)]
#[CoversClass(HoldRoundUntilEveryReviewerFinished::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerRole::class)]
final class ReviewWorkflowsTest extends KernelTestCase
{
    public function testAReviewerStartsThenReceivesItsVerdict(): void
    {
        $reviewer = $this->round(1)->reviewers()[0];

        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Start->value);
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Receive->value);

        self::assertSame(ReviewerState::Verdict, $reviewer->state());
    }

    public function testAReviewerThatNeverStartedCannotReceiveAVerdict(): void
    {
        $reviewer = $this->round(1)->reviewers()[0];

        self::assertFalse($this->reviewerMachine()->can($reviewer, ReviewerTransition::Receive->value));
        $this->expectException(NotEnabledTransitionException::class);

        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Receive->value);
    }

    public function testAReviewerThatNeverStartedCannotFail(): void
    {
        self::assertFalse($this->reviewerMachine()->can($this->round(1)->reviewers()[0], ReviewerTransition::Fail->value));
    }

    public function testAReviewerThatFinishedCannotStartOrFailAgain(): void
    {
        $reviewer = $this->round(1)->reviewers()[0];
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Start->value);
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Fail->value);

        self::assertFalse($this->reviewerMachine()->can($reviewer, ReviewerTransition::Start->value));
        self::assertFalse($this->reviewerMachine()->can($reviewer, ReviewerTransition::Fail->value));
        self::assertFalse($this->reviewerMachine()->can($reviewer, ReviewerTransition::Receive->value));
    }

    public function testARoundCannotCompleteWhileAReviewerHasNotFinished(): void
    {
        $round = $this->round(2);
        $this->finish($round->reviewers()[0]);
        $this->reviewerMachine()->apply($round->reviewers()[1], ReviewerTransition::Start->value);

        self::assertFalse($this->roundMachine()->can($round, RoundTransition::Complete->value));
    }

    public function testARoundCompletesOnceAndOnlyOnceWhenEveryReviewerFinished(): void
    {
        $round = $this->round(2);
        array_map($this->finish(...), $round->reviewers());

        self::assertTrue($this->roundMachine()->can($round, RoundTransition::Complete->value));
        $this->roundMachine()->apply($round, RoundTransition::Complete->value);

        self::assertSame(RoundState::Complete, $round->state());
        self::assertFalse($this->roundMachine()->can($round, RoundTransition::Complete->value));
    }

    public function testTheMarkingStoreRefusesASubjectThatKeepsNoPlace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StateMarkingStore()->getMarking(new stdClass());
    }

    private function finish(RoundReviewer $reviewer): void
    {
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Start->value);
        $this->reviewerMachine()->apply($reviewer, ReviewerTransition::Receive->value);
    }

    private function round(int $reviewers): ReviewRound
    {
        $round = new ReviewRound(Uuid::v7(), 'alpha', __DIR__, 'Add the thing.');

        foreach (range(1, $reviewers) as $number) {
            $round->addReviewer(new UuidV7(), new ReviewerRole('role-'.$number, 'Look.'), Tier::Standard);
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
