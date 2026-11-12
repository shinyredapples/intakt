<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class UnsignedCommitRefused extends RuntimeException implements ReportableFailure
{
    public static function commit(string $commit, string $reason): self
    {
        return new self(sprintf('The commit %s is not signed by the orchestrator\'s key (%s), so nothing is pushed.', $commit, $reason));
    }
}
