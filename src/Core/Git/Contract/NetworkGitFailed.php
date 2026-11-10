<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class NetworkGitFailed extends RuntimeException implements ReportableFailure
{
    public static function after(int $attempts, string $gitMessage): self
    {
        return new self(sprintf('git failed after %d attempts: %s', $attempts, trim($gitMessage)));
    }
}
