<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Without a stage until a demo run has started, so the page still holds the element a live update replaces.
final readonly class TourCaption
{
    /**
     * @param list<ReviewerOutcome> $reviewers
     */
    public function __construct(
        public ?TourStage $stage,
        public ?string $round,
        public array $reviewers = [],
    ) {
    }

    public static function beforeTheRun(): self
    {
        return new self(null, null);
    }

    public static function of(Review $review): self
    {
        $stage = TourStage::of($review);
        $latest = $review->latest();

        return new self($stage, $latest->round, $stage->isAboutTheReviewers() ? $latest->outcomes : []);
    }

    public function isPlayed(): bool
    {
        return $this->stage instanceof TourStage;
    }
}
