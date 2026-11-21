<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// In the order a demo run passes them, because the caption draws a stage as one dot by its place in this list. A later round passes the same stages again, so the tour never promises a stage the shipped one-round recording does not reach.
enum TourStage: string
{
    case ChangeArrives = 'change-arrives';
    case ReviewersRead = 'reviewers-read';
    case FindingsArrive = 'findings-arrive';
    case JudgeWeighs = 'judge-weighs';
    case RoundEnds = 'round-ends';

    public static function of(Review $review): self
    {
        $latest = $review->latest();

        if ($latest->isJudged()) {
            return self::RoundEnds;
        }

        return self::ofTheReviewers($latest);
    }

    public function sentence(): string
    {
        return match ($this) {
            self::ChangeArrives => 'A change arrives, and Intakt starts its review.',
            self::ReviewersRead => 'The reviewers read the change.',
            self::FindingsArrive => 'Findings arrive as each reviewer answers.',
            self::JudgeWeighs => 'The judge checks every finding against the change.',
            self::RoundEnds => 'Done: To fix lists what still needs changing.',
        };
    }

    public function number(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    public static function count(): int
    {
        return count(self::cases());
    }

    // A reviewer not started yet counts as still reading, so the judge's stage begins only once the whole panel is done.
    private static function ofTheReviewers(ReviewedRound $round): self
    {
        if ([] === $round->outcomes) {
            return self::ChangeArrives;
        }

        $answered = array_any($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Answered === $outcome->progress);
        $reading = count($round->outcomes) < $round->reviewers || array_any($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress);

        if (!$reading) {
            return self::JudgeWeighs;
        }

        return $answered ? self::FindingsArrive : self::ReviewersRead;
    }
}
