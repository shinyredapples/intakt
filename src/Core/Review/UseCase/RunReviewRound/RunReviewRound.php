<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\CommandMessage;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Domain\ReviewTarget;

final readonly class RunReviewRound implements CommandMessage
{
    public function __construct(
        public ReviewTarget $target,
        public Panel $panel,
        public RequestedRound $round,
    ) {
    }
}
