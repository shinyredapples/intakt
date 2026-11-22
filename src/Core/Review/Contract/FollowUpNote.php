<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A note a reviewer leaves in its verdict for work to do later; unlike FollowUp, it wraps no judged finding.
final readonly class FollowUpNote
{
    public function __construct(
        public string $text,
    ) {
    }
}
