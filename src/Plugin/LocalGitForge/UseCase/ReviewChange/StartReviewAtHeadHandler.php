<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\StartReviewAtHead;

#[AsCommandHandler]
final readonly class StartReviewAtHeadHandler
{
    public function __construct(private LocalChangeReview $review)
    {
    }

    public function __invoke(StartReviewAtHead $command): string
    {
        return $this->review->startAt(ReviewChangeInput::startedBy($command->start), $command->head);
    }
}
