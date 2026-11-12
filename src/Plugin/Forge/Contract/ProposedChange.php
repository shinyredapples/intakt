<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The branch of a project, the branch it is meant for and the builder's words, which together are one pull request.
final readonly class ProposedChange
{
    public function __construct(
        public string $project,
        public string $branch,
        public string $base,
        public ChangeText $text,
    ) {
    }
}
