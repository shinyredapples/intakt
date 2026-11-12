<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

final readonly class PullRequestComment
{
    public function __construct(
        public string $author,
        public string $head,
        public string $body,
    ) {
    }
}
