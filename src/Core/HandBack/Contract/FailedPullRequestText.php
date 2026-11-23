<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

final readonly class FailedPullRequestText
{
    public function __construct(
        public PullRequestTextOutcome $outcome,
        public PullRequestTextRoute $route,
    ) {
    }
}
