<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Infrastructure;

use Intakt\Core\Git\Contract\BuildClones;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Override;

// No Source line here, because a caller outside the module names only a branch and the trust check of a Source line stays with the command that reads one.
final readonly class MirrorBuildClones implements BuildClones
{
    public function __construct(private MakeBuildClone $clones)
    {
    }

    #[Override]
    public function make(string $project, string $branch, string $directory): void
    {
        $this->clones->make($project, new TaskBranch($branch), $directory);
    }
}
