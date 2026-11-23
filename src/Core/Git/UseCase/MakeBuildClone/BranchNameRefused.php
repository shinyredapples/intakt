<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class BranchNameRefused extends RuntimeException implements ReportableFailure
{
    public static function mainItself(string $branch): self
    {
        return new self(sprintf('the task\'s branch "%s" is main itself', $branch));
    }

    public static function notAcceptedByGit(string $branch): self
    {
        return new self(sprintf('"%s" is not a branch name git accepts', $branch));
    }
}
