<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Domain\RoundTransition;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;

// The name is spelled out because the listeners and StoredFindings address the machine by it, so renaming this class must not rename the machine.
#[AsWorkflow(
    name: 'review_round',
    supports: ReviewRound::class,
    initialMarking: RoundState::Collecting,
    markingStore: StateMarkingStore::class,
    places: RoundState::class,
)]
final class ReviewRoundStateMachine
{
    #[Transition(from: RoundState::Collecting, to: RoundState::Complete)]
    public const string COMPLETE = RoundTransition::Complete->value;

    #[Transition(from: RoundState::Complete, to: RoundState::Judged)]
    public const string JUDGE = RoundTransition::Judge->value;
}
