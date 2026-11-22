<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The steps of a review from its first pass to the merge: every earlier pass and fix as done, the current step with its role standing on it, then what follows, so the owner sees the whole history at a glance.
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
        $number = $latest->number;

        if (!$latest->isJudged() && !$latest->hasEnded()) {
            return [...self::before($number), self::pass($number, PathStepState::Now, StepRole::CodeReviewer), new PathStep(self::MERGE, PathStepState::Next)];
        }

        return match ($latest->reviewOutcome()) {
            ReviewOutcome::Approved => [...self::before($number), self::pass($number, PathStepState::Done), new PathStep(self::MERGE, PathStepState::Now, StepRole::Maintainer)],
            ReviewOutcome::ChangesRequested => [
                ...self::before($number),
                self::pass($number, PathStepState::Done),
                new PathStep(self::FIX, PathStepState::Now, StepRole::Developer),
                self::pass(null === $number ? null : $number + 1, PathStepState::Next),
                new PathStep(self::MERGE, PathStepState::Next),
            ],
            // A blocked, failed or unrecorded outcome has no next step the path could name, so the verdict card alone says it.
            default => [],
        };
    }

    /**
     * @return list<PathStep>
     */
    // Each earlier pass was followed by a fix, because a later pass only starts after changes were needed; a round without a number has no history to show.
    private static function before(?int $number): array
    {
        $steps = [];
        for ($earlier = 1; $earlier < ($number ?? 1); ++$earlier) {
            $steps[] = self::pass($earlier, PathStepState::Done);
            $steps[] = new PathStep(self::FIX, PathStepState::Done);
        }

        return $steps;
    }

    private static function pass(?int $number, PathStepState $state, ?StepRole $who = null): PathStep
    {
        return new PathStep(null === $number ? 'Pass' : 'Pass '.$number, $state, $who);
    }
}
