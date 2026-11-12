<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Domain;

use DateTimeImmutable;
use Intakt\Core\ReviewerForLoop\Contract\PullRequestComment;

final readonly class PostedComment
{
    public function __construct(
        public PullRequestComment $comment,
        public DateTimeImmutable $postedAt,
    ) {
    }
}
