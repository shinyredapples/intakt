<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Not FollowUp, because that one wraps a judged finding and means a follow-up the judge filed.
final readonly class FollowUpNote
{
    public function __construct(
        public string $text,
    ) {
    }
}
