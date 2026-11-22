<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One change somebody works on now, with the role standing on its step; a task the build runs has no round yet, so only a review's card opens a page.
#[Exclude]
final readonly class WorkingCard
{
    public function __construct(
        public string $title,
        public string $project,
        public WorkStep $step,
        public Character $character,
        public ?string $round,
    ) {
    }

    // A round from before the ledger named its change has no change, so the round names it, as on the board card.
    public static function ofReview(Review $review): self
    {
        $latest = $review->latest();
        $title = '' === $review->change ? 'Round '.$latest->round : $review->change;

        return new self($title, $review->project, WorkStep::Review, Character::CodeReviewer, $latest->round);
    }

    public static function ofTask(BuildTask $task): self
    {
        return new self($task->task, $task->project, WorkStep::Build, Character::Developer, null);
    }
}
