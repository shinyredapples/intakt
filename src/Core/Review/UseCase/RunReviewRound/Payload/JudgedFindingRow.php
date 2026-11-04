<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

use Intakt\Core\Review\Contract\Severity;

final readonly class JudgedFindingRow
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $text,
    ) {
    }
}
