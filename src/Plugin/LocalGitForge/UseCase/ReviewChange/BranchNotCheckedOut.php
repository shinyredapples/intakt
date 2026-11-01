<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use RuntimeException;

final class BranchNotCheckedOut extends RuntimeException
{
    public static function named(string $branch): self
    {
        return new self('Check out the branch "'.$branch.'" in the repository, with no uncommitted changes to tracked files, before reviewing it: the reviewers read the files there.');
    }
}
