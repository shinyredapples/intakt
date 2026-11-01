<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\VerdictIdentity;

// Together, because the identity records the head and the terms the subject is reviewed at, and a round stores both.
final readonly class ReviewTarget
{
    public function __construct(
        public ReviewSubject $subject,
        public VerdictIdentity $identity,
    ) {
    }
}
