<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// In the contract, because the build task reports it from both the hand-back check and the pre-push check.
final class HandBackBranchRefused extends RuntimeException implements ReportableFailure
{
    public static function isMain(string $branch): self
    {
        return new self(sprintf('the task\'s branch "%s" is main itself', $branch));
    }

    public static function notAccepted(string $branch): self
    {
        return new self(sprintf('"%s" is not a branch name git accepts', $branch));
    }
}
