<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;

// The name is spelled out because ReviewerMoves addresses the machine by it, so renaming this class must not rename the machine.
#[AsWorkflow(
    name: 'reviewer',
    supports: RoundReviewer::class,
    initialMarking: ReviewerState::Queued,
    markingStore: StateMarkingStore::class,
    places: ReviewerState::class,
)]
final class ReviewerStateMachine
{
    #[Transition(from: ReviewerState::Queued, to: ReviewerState::Running)]
    public const string START = ReviewerTransition::Start->value;

    #[Transition(from: ReviewerState::Running, to: ReviewerState::Verdict)]
    public const string RECEIVE = ReviewerTransition::Receive->value;

    #[Transition(from: ReviewerState::Running, to: ReviewerState::Failed)]
    public const string FAIL = ReviewerTransition::Fail->value;

    #[Transition(from: ReviewerState::Running, to: ReviewerState::Queued)]
    public const string REQUEUE = ReviewerTransition::Requeue->value;

    // Both places, because a message can reach the failure transport while its reviewer waits in the queue (handed back) or still looks running; a state machine transition has one input place, so the attribute is repeated.
    #[Transition(from: ReviewerState::Queued, to: ReviewerState::Blocked)]
    #[Transition(from: ReviewerState::Running, to: ReviewerState::Blocked)]
    public const string BLOCK = ReviewerTransition::Block->value;
}
