<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// In the order a demo run passes them, because the caption counts a stage by its place in this list.
enum TourStage: string
{
    case ChangeArrives = 'change-arrives';
    case ReviewersRead = 'reviewers-read';
    case FindingsArrive = 'findings-arrive';
    case JudgeWeighs = 'judge-weighs';
    case RoundEnds = 'round-ends';
    case NextRound = 'next-round';
    case FindingsClosed = 'findings-closed';

    public static function of(Review $review): self
    {
        $latest = $review->latest();

        if (count($review->rounds) > 1) {
            return self::ofALaterRound($review, $latest);
        }

        if ($latest->isJudged()) {
            return self::RoundEnds;
        }

        return self::ofTheReviewers($latest);
    }

    // At most about 15 words in the dashboard's plain words, so the caption stays one line on a phone (#3133).
    public function sentence(): string
    {
        return match ($this) {
            self::ChangeArrives => 'A change arrives; pass 1 starts.',
            self::ReviewersRead => 'The AI reviewers read the change.',
            self::FindingsArrive => 'The first findings are in.',
            self::JudgeWeighs => 'The judge weighs every finding.',
            self::RoundEnds => 'Checked: the review shows what to fix.',
            self::NextRound => 'The fixed change comes back for pass 2.',
            self::FindingsClosed => 'Pass 2 is checked: nothing left to fix.',
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

    private static function ofALaterRound(Review $review, ReviewedRound $latest): self
    {
        if (!$latest->isJudged()) {
            return self::NextRound;
        }

        return [] === $review->openFindings() ? self::FindingsClosed : self::RoundEnds;
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
