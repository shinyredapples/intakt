<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\Panel;

final readonly class RunReviewRound
{
    public function __construct(
        public string $project,
        public string $checkoutRoot,
        public string $task,
        public Panel $panel,
    ) {
    }
}
