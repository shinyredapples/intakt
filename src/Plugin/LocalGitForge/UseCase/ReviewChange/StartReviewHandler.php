<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\StartReview;

// Lives in the forge plugin because the change is read from its repository, which the core review module may not reach.
#[AsCommandHandler]
final readonly class StartReviewHandler
{
    public function __construct(private LocalChangeReview $review)
    {
    }

    // Returns the id of the queued round, because the caller shows it and the round is judged later by the workers.
    public function __invoke(StartReview $command): string
    {
        $input = new ReviewChangeInput();
        $input->project = $command->project;
        $input->task = $command->task;
        $input->change = $command->change;
        $input->repository = $command->source->repository;
        $input->base = $command->source->base;

        return $this->review->startOf($input);
    }
}
