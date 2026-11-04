<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

// One judged finding as a JudgeDone row keeps it.
final readonly class JudgedFindingRow
{
    public function __construct(
        public string $id,
        public string $severity,
        public string $text,
    ) {
    }
}
