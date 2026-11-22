<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The steps from a review pass to the merge, with the role of the current step standing on it, so the owner sees where the change stands without reading the timeline.
final readonly class ReviewPath
{
    private const string FIX = 'Fix';
    private const string MERGE = 'Merge';

    /**
     * @return list<PathStep>
     */
    public static function of(Review $review): array
    {
        $latest = $review->latest();

        if (!$latest->isJudged() && !$latest->hasEnded()) {
            return self::inReview($latest->number);
        }

        return match ($latest->reviewOutcome()) {
            ReviewOutcome::Approved => [self::pass($latest->number, PathStepState::Done), new PathStep(self::MERGE, PathStepState::Now, StepRole::Maintainer)],
            ReviewOutcome::ChangesRequested => self::toFix($latest->number),
            // A blocked, failed or unrecorded outcome has no next step the path could name, so the verdict card alone says it.
            default => [],
        };
    }

    /**
     * @return list<PathStep>
     */
    private static function inReview(?int $number): array
    {
        $steps = [self::pass($number, PathStepState::Now, StepRole::CodeReviewer), new PathStep(self::MERGE, PathStepState::Next)];

        // A later pass follows a fix, so the path shows that step as done before it.
        return null !== $number && $number > 1 ? [new PathStep(self::FIX, PathStepState::Done), ...$steps] : $steps;
    }

    /**
     * @return list<PathStep>
     */
    private static function toFix(?int $number): array
    {
        return [
            self::pass($number, PathStepState::Done),
            new PathStep(self::FIX, PathStepState::Now, StepRole::Developer),
            self::pass(null === $number ? null : $number + 1, PathStepState::Next),
            new PathStep(self::MERGE, PathStepState::Next),
        ];
    }

    private static function pass(?int $number, PathStepState $state, ?StepRole $who = null): PathStep
    {
        return new PathStep(null === $number ? 'Pass' : 'Pass '.$number, $state, $who);
    }
}
