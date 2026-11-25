<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\ResolvedModel;

final readonly class ReviewJobRequest
{
    public function __construct(
        public string $project,
        // A clone at the head with a .git folder of its own, never a worktree, because the job's script refuses any other checkout.
        public string $checkout,
        public string $head,
        // The panel reviewer's role name, so two reviewers of one head run as two jobs.
        public string $reviewer,
        public ResolvedModel $model,
        // Passed to the CLI as it is, so untrusted text in it is fenced by whoever writes it.
        public string $prompt,
        // Null only for a run whose CLI needs none, because a job is given the login of the account it runs on and no other.
        public ?AccountLogin $login = null,
    ) {
    }
}
