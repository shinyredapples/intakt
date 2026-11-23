<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Thrown, because a miss the ledger did not take would be sent back again instead of going to the reviewers.
final class PullRequestTextRoundNotRecorded extends RuntimeException implements ReportableFailure
{
    public static function for(string $kind, string $failure): self
    {
        return new self(sprintf('the pull request text\'s %s line was not recorded: %s', $kind, $failure));
    }
}
