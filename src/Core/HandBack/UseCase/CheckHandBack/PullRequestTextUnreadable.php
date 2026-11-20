<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class PullRequestTextUnreadable extends RuntimeException implements ReportableFailure
{
    public static function at(string $path, Throwable $cause): self
    {
        return new self(sprintf('the pull request text "%s" cannot be read', $path), 0, $cause);
    }
}
