<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Plugin\Forge\Contract\ChangeText;

// The repository is the bare one that holds the accepted head (the project's mirror), because the signing writes its commits there; the remote is where the branch goes.
final readonly class BranchPushRequest
{
    public function __construct(
        public string $project,
        public string $repository,
        public string $remote,
        public string $branch,
        public AcceptedHandBack $accepted,
        public ChangeText $text,
    ) {
    }
}
