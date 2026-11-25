<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class WatchedProject
{
    public function __construct(
        public string $name,
        public ChangeSource $source,
        // Shared by every pull request because the forge's pull request text is not read yet.
        public string $task,
    ) {
    }

    public function startAt(OpenChange $open, Labels $labels = new Labels()): StartReviewAtHead
    {
        return new StartReviewAtHead(new StartReview($this->name, $this->task, new RequestedRound($open->id->value, null, $this->source), $labels), $open->head);
    }
}
