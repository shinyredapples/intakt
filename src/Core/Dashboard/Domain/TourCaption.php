<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Without a stage until a demo run has started, so the page still holds the element a live update replaces.
final readonly class TourCaption
{
    public function __construct(
        public ?TourStage $stage,
        public ?string $round,
    ) {
    }

    public static function beforeTheRun(): self
    {
        return new self(null, null);
    }

    public static function of(Review $review): self
    {
        return new self(TourStage::of($review), $review->latest()->round);
    }

    public function isPlayed(): bool
    {
        return $this->stage instanceof TourStage;
    }
}
