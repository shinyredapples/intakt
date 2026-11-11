<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use DateTimeImmutable;
use Intakt\Core\Shadow\Contract\PullRequestComment;

final readonly class PostedComment
{
    public function __construct(
        public PullRequestComment $comment,
        public DateTimeImmutable $postedAt,
    ) {
    }
}
