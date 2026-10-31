<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Domain\ReviewSubject;

final readonly class RunReviewRound
{
    public function __construct(
        public ReviewSubject $subject,
        public Panel $panel,
    ) {
    }
}
