<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\SampleChange;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded from the container, because SetupSteps builds one per page.
#[Exclude]
final readonly class FirstReviewStep
{
    public function __construct(
        public ?Review $review,
        public bool $sampleCanStart,
    ) {
    }

    public function state(): StepState
    {
        return match (true) {
            $this->review instanceof Review => StepState::Done,
            $this->sampleCanStart => StepState::Missing,
            default => StepState::NotNeeded,
        };
    }

    public function sentence(): string
    {
        if (!$this->review instanceof Review) {
            return $this->sampleCanStart
                ? sprintf('No review has run yet: start the review of the change %s of the %s project.', SampleChange::CHANGE, SampleChange::PROJECT)
                : 'No review has run yet, and this page has no sample change to start one from: your coding agent can ask for a review of a change of your own project.';
        }

        return sprintf('The first review, of the change %s of the %s project, %s.', $this->review->change, $this->review->project, $this->outcome($this->review->latest()));
    }

    // The round the link opens, the latest of the review, because its page shows every round of the change.
    public function round(): ?string
    {
        return $this->review?->latest()->round;
    }

    // Any round, not the latest, because an approval is a milestone that a later round started or sent back does not undo.
    public function mascot(): MascotPose
    {
        $approved = array_any($this->review->rounds ?? [], static fn (ReviewedRound $round): bool => Decision::Approve === $round->outcome);

        return $approved ? MascotPose::Bowing : MascotPose::Conducting;
    }

    private function outcome(ReviewedRound $round): string
    {
        return match (true) {
            $round->outcome instanceof Decision => 'is judged with the outcome '.$round->outcome->value,
            $round->isJudged() => 'is judged; its outcome was not recorded',
            $round->hasEnded() => 'ended without a call of the judge',
            default => 'is in progress',
        };
    }
}
